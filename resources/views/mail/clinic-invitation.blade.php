<x-mail::message>
# Join {{ $clinic->name }}

{{ $inviter ?? 'The clinic' }} has invited you to work in **{{ $clinic->name }}** on Scribe as **{{ $roleLabel }}**.

<x-mail::button :url="$url">
See the invitation
</x-mail::button>

You can accept or decline. If you don't have a Scribe account yet, you'll create one. The link works for {{ $days }} days.

If you weren't expecting this, ignore this email: nothing changes unless you accept.
</x-mail::message>
