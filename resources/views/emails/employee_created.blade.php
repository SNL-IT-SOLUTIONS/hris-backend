<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <title>Welcome to the Company</title>
    <style>
        span {
            color: #f2f2f5;
            background: blue;
            padding: 5px 10px;
            border-radius: 5px;
        }

        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
        }

        .container {
            max-width: 600px;
            margin: auto;
            padding: 20px;
            border: 1px solid #eee;
        }

        h2 {
            color: #1f13cf;
        }

        .button {
            display: inline-block;
            padding: 10px 20px;
            color: #0a0a0a;
            text-decoration: none;
            border-radius: 5px;
        }

        .footer {
            margin-top: 20px;
            font-size: 12px;
            color: #888;
        }
    </style>
</head>

<body>
    <div class="container">
        <h2>Welcome, {{ $employee->first_name }} {{ $employee->last_name }}!</h2>
        <p>We are excited to have you join our team at the company. Your employee account has been successfully created.
        </p>

        <p><strong>Email:</strong> {{ $employee->email }}</p>
        <p><strong>Password:</strong> {{ $password }}</p>

        <p>Please log in using your credentials and update your password after the first login to keep your account
            secure.</p>

        <a href="{{ url('/login') }}" class="button"><span>Login to Your Account</span></a>

        <p class="footer">If you have any questions, contact HR at hr@company.com</p>
    </div>
</body>

</html>
