<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Rats\Zkteco\Lib\ZKTeco;
use App\Models\User;
use App\Models\Attendance;
use App\Models\Employee;
use Carbon\Carbon;

class SyncZKAttendance extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'zk:sync';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync attendance logs from ZKTeco device';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $zk = new ZKTeco('192.168.100.201');

        if (!$zk->connect()) {
            return;
        }

        $logs = $zk->getAttendance();
        $this->info('Logs found: ' . count($logs));


        foreach ($logs as $log) {

            $employee = Employee::where('face_id', $log['id'])->first();

            if (!$employee) {
                continue;
            }

            $time = Carbon::parse($log['timestamp']);

            // Prevent importing the same biometric log twice
            $alreadyImported = Attendance::where('employee_id', $employee->id)
                ->where(function ($q) use ($time) {
                    $q->where('clock_in', $time)
                        ->orWhere('clock_out', $time);
                })
                ->exists();

            if ($alreadyImported) {
                continue;
            }

            $openAttendance = Attendance::where('employee_id', $employee->id)
                ->whereNull('clock_out')
                ->latest('clock_in')
                ->first();

            if ($openAttendance) {

                // Second scan = Clock Out
                $openAttendance->update([
                    'clock_out' => $time,
                ]);

                $this->info("Clock Out: {$employee->first_name}");
            } else {

                // First scan = Clock In
                Attendance::create([
                    'employee_id' => $employee->id,
                    'clock_in'    => $time,
                    'method'      => 'Facial Recognition',
                ]);

                $this->info("Clock In: {$employee->first_name}");
            }
        }
    }
}
