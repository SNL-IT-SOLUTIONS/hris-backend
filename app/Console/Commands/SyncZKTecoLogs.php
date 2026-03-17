<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Models\Attendance;
use Carbon\Carbon;
use ZKLibrary\ZKLibrary;

class SyncZKTecoLogs extends Command
{
    protected $signature = 'attendance:sync-zkteco';
    protected $description = 'Fetch logs from ZKTeco device and update Attendance table';

    protected $deviceIp = '192.168.1.201';
    protected $devicePort = 4370;

    public function handle()
    {
        try {
            $zk = new ZKLibrary($this->deviceIp, $this->devicePort);
            $zk->connect();

            $logs = $zk->getAttendance();

            foreach ($logs as $log) {
                $user = User::where('device_user_id', $log['user_id'])->first();

                if (!$user) continue; // unknown device ID

                $timestamp = Carbon::parse($log['timestamp']);

                // Check if user has an open attendance
                $openAttendance = Attendance::where('employee_id', $user->id)
                    ->whereNull('clock_out')
                    ->first();

                if (!$openAttendance) {
                    // Clock In
                    Attendance::create([
                        'employee_id' => $user->id,
                        'clock_in' => $timestamp,
                        'status' => 'Present',
                        'method' => 'Biometric',
                    ]);
                    $this->info("Clocked IN: {$user->first_name} at {$timestamp}");
                } else {
                    // Clock Out
                    $openAttendance->clock_out = $timestamp;
                    $openAttendance->hours_worked = Carbon::parse($openAttendance->clock_in)
                        ->diffInMinutes($timestamp) / 60;
                    $openAttendance->method = 'Biometric';
                    $openAttendance->save();
                    $this->info("Clocked OUT: {$user->first_name} at {$timestamp}");
                }
            }

            $zk->disconnect();
            $this->info("Sync complete ✅");
        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
        }
    }
}
