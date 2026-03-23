<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Contact Inquiry</title>
</head>
<body style="margin:0;padding:24px;background:#f3f7fd;font-family:Arial,Helvetica,sans-serif;color:#163055;">
    <div style="max-width:680px;margin:0 auto;background:#ffffff;border:1px solid #d8e5f8;border-radius:18px;overflow:hidden;">
        <div style="padding:24px 24px 18px;background:linear-gradient(135deg,#143362,#2c67b7);color:#ffffff;">
            <div style="font-size:12px;letter-spacing:0.08em;text-transform:uppercase;opacity:0.82;font-weight:700;">RentaHub Support</div>
            <h1 style="margin:12px 0 0;font-size:26px;line-height:1.1;">New Contact Us Inquiry</h1>
        </div>

        <div style="padding:24px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin-bottom:20px;">
                <tr>
                    <td style="padding:0 0 12px;font-size:14px;color:#557093;width:160px;"><strong>Sender Name</strong></td>
                    <td style="padding:0 0 12px;font-size:14px;color:#163055;">{{ $name }}</td>
                </tr>
                <tr>
                    <td style="padding:0 0 12px;font-size:14px;color:#557093;"><strong>Sender Email</strong></td>
                    <td style="padding:0 0 12px;font-size:14px;color:#163055;">{{ $email }}</td>
                </tr>
                <tr>
                    <td style="padding:0 0 12px;font-size:14px;color:#557093;"><strong>Submitted At</strong></td>
                    <td style="padding:0 0 12px;font-size:14px;color:#163055;">{{ $submittedAt }}</td>
                </tr>
                <tr>
                    <td style="padding:0 0 12px;font-size:14px;color:#557093;"><strong>IP Address</strong></td>
                    <td style="padding:0 0 12px;font-size:14px;color:#163055;">{{ $ipAddress ?: 'Unavailable' }}</td>
                </tr>
                <tr>
                    <td style="padding:0;font-size:14px;color:#557093;"><strong>User Agent</strong></td>
                    <td style="padding:0;font-size:14px;color:#163055;">{{ $userAgent ?: 'Unavailable' }}</td>
                </tr>
            </table>

            <div style="border:1px solid #dbe7fa;border-radius:14px;background:#f8fbff;padding:18px;">
                <div style="font-size:12px;letter-spacing:0.08em;text-transform:uppercase;color:#3c65a9;font-weight:700;margin-bottom:10px;">Message</div>
                <div style="font-size:15px;line-height:1.7;color:#173359;white-space:pre-wrap;">{{ $inquiryMessage }}</div>
            </div>
        </div>
    </div>
</body>
</html>
