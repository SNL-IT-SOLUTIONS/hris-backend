<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class EndOfDayReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public $subjectLine;
    public $reportContent;

    public function __construct($subjectLine, $reportContent)
    {
        $this->subjectLine = $subjectLine;
        $this->reportContent = $reportContent;
    }

    public function build()
    {
        return $this->subject($this->subjectLine)
            ->view('emails.eod_report');
    }
}
