<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AdjustmentRequestMail extends Mailable
{
    use SerializesModels;

    public $attendance;
    public $employee;

    public function __construct($attendance, $employee)
    {
        $this->attendance = $attendance;
        $this->employee = $employee;
    }

    public function build()
    {
        return $this->subject('DTR Adjustment Request')
            ->html("
                <h2>DTR Adjustment Request</h2>
                <p><strong>Employee:</strong> {$this->employee->first_name} {$this->employee->last_name}</p>
                <p><strong>Email:</strong> {$this->employee->email}</p>
                <hr>
                <p><strong>Original Clock In:</strong> {$this->attendance->clock_in}</p>
                <p><strong>Original Clock Out:</strong> {$this->attendance->clock_out}</p>
                <br>
                <p><strong>Requested Clock In:</strong> {$this->attendance->adjusted_clock_in}</p>
                <p><strong>Requested Clock Out:</strong> {$this->attendance->adjusted_clock_out}</p>
                <br>
                <p><strong>Reason:</strong></p>
                <p>{$this->attendance->adjustment_reason}</p>
                <hr>
                <p>Please review this request in the HR system.</p>
            ");
    }
}
