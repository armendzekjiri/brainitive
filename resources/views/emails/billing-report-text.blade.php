{{-- Plain-text part of App\Mail\BillingReportMail. --}}
{!! filled($body) ? $body : "Hello,\n\nplease find the document attached." !!}


Attached: {!! $filename !!}

{!! $sender !!}{!! filled($company) ? ' · '.$company : '' !!}
