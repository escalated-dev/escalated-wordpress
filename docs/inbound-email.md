# Inbound email

## Inbound Email Webhooks

Inbound route pattern:

- `POST /wp-json/escalated/v1/inbound/{adapter}`

Supported adapters:

- `mailgun`
- `postmark`
- `ses`

How an inbound email is matched to a ticket:

- **Thread matching**: when an inbound secret is set (the `escalated_email_inbound_secret` option or the `ESCALATED_EMAIL_INBOUND_SECRET` constant), outbound mail carries a signed Reply-To (`reply+{id}.{hmac8}@domain`) and only that address links an inbound email to a ticket. Without a secret, the `In-Reply-To` / `References` Message-IDs and the subject reference (e.g. `[ESC-00001]`) are used instead.
- **Sender check**: a matched email becomes a reply only when the `From` address is the ticket's requester (the guest email, or the requester's WordPress email, case-insensitive). It is posted as that requester. Staff identity is never taken from the `From` header, so agents reply in the admin, not by email.
- **Anything else** (no match, or a sender who is not the requester) creates a new ticket, so mail is never dropped.
- **Reopen**: an accepted reply reopens a resolved or closed ticket.

Set a secret in production; Message-IDs and ticket references can be guessed.
