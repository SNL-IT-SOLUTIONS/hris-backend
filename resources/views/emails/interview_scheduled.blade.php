<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Interview Scheduled</title>
    <style>
        body {
            font-family: 'Segoe UI', Roboto, Arial, sans-serif;
            background-color: #f4f6f8;
            margin: 0;
            padding: 0;
        }

        .email-container {
            max-width: 600px;
            margin: 40px auto;
            background-color: #ffffff;
            border-radius: 12px;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.08);
            overflow: hidden;
        }

        .email-header {
            background-color: #004aad;
            color: #ffffff;
            text-align: center;
            padding: 25px 15px;
        }

        .email-header h1 {
            margin: 0;
            font-size: 24px;
        }

        .email-body {
            padding: 30px;
            color: #333333;
        }

        .email-body h2 {
            color: #004aad;
            font-size: 20px;
            margin-bottom: 10px;
        }

        .email-body p {
            font-size: 15px;
            line-height: 1.6;
            margin-bottom: 12px;
        }

        .email-body strong {
            color: #222222;
        }

        .details {
            background-color: #f8fafc;
            border-left: 4px solid #004aad;
            padding: 15px 20px;
            border-radius: 8px;
            margin-top: 15px;
            margin-bottom: 20px;
        }

        a {
            color: #004aad;
            text-decoration: none;
        }

        a:hover {
            text-decoration: underline;
        }

        .footer {
            text-align: center;
            background-color: #f1f3f6;
            padding: 15px;
            font-size: 13px;
            color: #777;
        }

        @media (max-width: 600px) {
            .email-body {
                padding: 20px;
            }
        }
    </style>
</head>

<body>
    <div class="email-container">
        <div class="email-header">
            <h1>Interview Scheduled</h1>
        </div>

        <div class="email-body">
            <h2>Hello {{ $applicant->first_name }} {{ $applicant->last_name }},</h2>
            <p>We’re pleased to inform you that your interview for the position of
                <strong>{{ $interview->position }}</strong> has been successfully scheduled.
            </p>

            <div class="details">
                <p><strong>Date:</strong> {{ \Carbon\Carbon::parse($interview->scheduled_at)->format('F j, Y g:i A') }}
                </p>
                <p><strong>Mode:</strong> {{ ucfirst($interview->mode) }}</p>

                @if ($interview->mode == 'online' && $interview->location_link)
                    <p><strong>Meeting Link:</strong>
                        <a href="{{ $interview->location_link }}" target="_blank">{{ $interview->location_link }}</a>
                    </p>
                @elseif($interview->mode == 'in-person')
                    <p><strong>Location:</strong> Please arrive at the HR Office.</p>
                @endif
            </div>

            <p>Please make sure to be on time and prepare accordingly. If you have any questions, don’t hesitate to
                reach out to us.</p>

            <p>Best regards,<br>
                <strong>HR Team</strong>
            </p>
        </div>

        <div class="footer">
            &copy; {{ date('Y') }} Your Company Name. All rights reserved.
        </div>
    </div>
</body>

</html>
