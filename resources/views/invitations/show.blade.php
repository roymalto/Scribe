<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Invitation · Scribe</title>
<style>
  :root { --bg:#fafafa; --card:#fff; --text:#18181b; --muted:#71717a; --line:#e4e4e7; --accent:#0d9488; --danger:#dc2626; }
  @media (prefers-color-scheme: dark) { :root { --bg:#09090b; --card:#18181b; --text:#fafafa; --muted:#a1a1aa; --line:#27272a; } }
  * { box-sizing: border-box; }
  body { margin:0; background:var(--bg); color:var(--text); font:16px/1.5 system-ui, sans-serif; }
  main { max-width:28rem; margin:3rem auto; padding:0 16px; }
  .card { background:var(--card); border:1px solid var(--line); border-radius:12px; padding:1.5rem; }
  h1 { font-size:1.35rem; margin:0 0 .75rem; }
  .brand { text-align:center; font-weight:700; margin-bottom:1rem; }
  .muted { color:var(--muted); font-size:.9rem; }
  label { display:block; font-weight:600; font-size:.9rem; margin:.9rem 0 .3rem; }
  input { width:100%; padding:.6rem .7rem; border:1px solid var(--line); border-radius:8px; background:var(--card); color:var(--text); font:inherit; }
  .row { display:flex; gap:.6rem; margin-top:1.25rem; }
  button, .btn { flex:1; display:inline-block; text-align:center; padding:.65rem; border-radius:8px; border:1px solid var(--line);
                 background:var(--card); color:var(--text); font:inherit; font-weight:600; cursor:pointer; text-decoration:none; }
  .primary { background:var(--accent); border-color:var(--accent); color:#fff; }
  .error { color:var(--danger); font-size:.9rem; margin-top:.75rem; }
  .ok { color:var(--accent); font-size:.95rem; margin-top:.75rem; }
</style>
</head>
<body>
<main>
  <div class="brand">Scribe</div>
  <div class="card">
    <h1>Join {{ $inv->clinic_name }}</h1>
    <p>You have been invited to work in <strong>{{ $inv->clinic_name }}</strong> as <strong>{{ $roleLabel }}</strong>.</p>
    <p class="muted">Invitation for {{ $inv->email }}</p>

    @if (session('status'))
      <p class="ok">{{ session('status') }}</p>
    @endif
    @foreach ($errors->all() as $e)
      <p class="error">{{ $e }}</p>
    @endforeach

    @if ($inv->status === 'accepted')
      <p class="ok">This invitation has been accepted.</p>
      <div class="row"><a class="btn primary" href="/admin">Open Scribe</a></div>
    @elseif ($inv->status === 'declined')
      @unless (session('status'))<p class="muted">This invitation was declined.</p>@endunless
    @elseif ($inv->status === 'expired')
      <p class="error">This invitation has expired. Ask the clinic to send a new one.</p>
    @elseif ($user && strtolower($user->email) !== strtolower($inv->email))
      <p class="error">You are signed in as {{ $user->email }}. Sign out, then open this link again to answer as {{ $inv->email }}.</p>
      <form method="post" action="{{ route('filament.admin.auth.logout') }}">@csrf
        <div class="row"><button type="submit">Sign out</button></div>
      </form>
    @elseif ($user)
      <form method="post" action="{{ route('invitations.answer', $token) }}">@csrf
        <div class="row">
          <button type="submit" name="answer" value="decline">Decline</button>
          <button type="submit" name="answer" value="accept" class="primary">Accept</button>
        </div>
      </form>
    @elseif ($hasAccount)
      <p class="muted">You already have a Scribe account. Sign in to accept or decline.</p>
      <div class="row"><a class="btn primary" href="/admin/login">Sign in</a></div>
    @else
      <p class="muted">Create your Scribe account to accept. If you don't want to join, ignore the email: nothing changes.</p>
      <form method="post" action="{{ route('invitations.register', $token) }}">@csrf
        <label for="name">Your name</label>
        <input id="name" name="name" value="{{ old('name') }}" required autocomplete="name">
        <label for="password">Password (12 or more characters)</label>
        <input id="password" name="password" type="password" required minlength="12" autocomplete="new-password">
        <label for="password_confirmation">Password again</label>
        <input id="password_confirmation" name="password_confirmation" type="password" required minlength="12" autocomplete="new-password">
        <div class="row"><button type="submit" class="primary">Create account and accept</button></div>
      </form>
    @endif
  </div>
</main>
</body>
</html>
