<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ClockInNotification extends Mailable
{
    use SerializesModels;

    public $user;
    public $date;

    public function __construct($user, $date)
    {
        $this->user = $user;
        $this->date = $date;
    }

    public function build()
    {
        return $this->subject('Clockin Notification for ' . $this->user->first_name . ' ' . $this->user->last_name . ' on ' . $this->date)
            ->view('emails.clockin');
    }
}
