{{-- Billing report mail to a client (App\Mail\BillingReportMail). The admin's message is plain text: escaped, line breaks kept. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; background-color: #F4F6F8; color: #1f2933;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color: #F4F6F8;">
        <tr>
            <td align="center" style="padding: 40px 20px;">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width: 560px; background-color: #ffffff; border-radius: 16px; border: 1px solid rgba(192, 197, 192, 0.4);">
                    <tr>
                        <td style="padding: 32px 32px 8px 32px; font-size: 15px; line-height: 1.6;">
                            @if (filled($body))
                                {!! nl2br(e($body)) !!}
                            @else
                                Hello,<br><br>please find the document attached.
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 16px 32px 32px 32px;">
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="background-color: #F4F6F8; border-radius: 10px; width: 100%;">
                                <tr>
                                    <td style="padding: 12px 16px; font-size: 13px; color: #485E72;">
                                        Attached: <strong>{{ $filename }}</strong>
                                    </td>
                                </tr>
                            </table>
                            <p style="margin: 20px 0 0 0; font-size: 13px; color: #66717d;">
                                {{ $sender }}@if (filled($company)) · {{ $company }}@endif<br>
                                Reply to this email to reach {{ $sender }} directly.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
