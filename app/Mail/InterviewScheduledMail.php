<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class InterviewScheduledMail extends Mailable
{
    use Queueable, SerializesModels;

    public $applicant;
    public $interview;

    public function __construct($applicant, $interview)
    {
        $this->applicant = $applicant;
        $this->interview = $interview;
    }

    public function build()
    {
        return $this->subject('Interview Scheduled Notification')
            ->view('emails.interview_scheduled');
    }
}
