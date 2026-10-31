<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Set up Scribe</title>
<style>
  :root { --bg:#fafafa; --card:#fff; --text:#18181b; --muted:#71717a; --line:#e4e4e7; --accent:#0d9488; --danger:#dc2626; }
  @media (prefers-color-scheme: dark) { :root { --bg:#09090b; --card:#18181b; --text:#fafafa; --muted:#a1a1aa; --line:#27272a; } }
  * { box-sizing: border-box; }
  body { margin:0; background:var(--bg); color:var(--text); font:16px/1.5 system-ui, sans-serif; }
  main { max-width:32rem; margin:3rem auto; padding:0 16px; }
  .card { background:var(--card); border:1px solid var(--line); border-radius:12px; padding:1.5rem; }
  h1 { font-size:1.35rem; margin:0 0 .5rem; }
  h2 { font-size:1rem; margin:1.5rem 0 0; padding-top:1rem; border-top:1px solid var(--line); }
  .brand { text-align:center; margin-bottom:1rem; }
  .brand img { height:2.25rem; }
  .muted { color:var(--muted); font-size:.9rem; }
  label { display:block; font-weight:600; font-size:.9rem; margin:.9rem 0 .3rem; }
  label span { font-weight:400; color:var(--muted); }
  input { width:100%; padding:.6rem .7rem; border:1px solid var(--line); border-radius:8px; background:var(--card); color:var(--text); font:inherit; }
  .row { display:flex; gap:.6rem; margin-top:1.5rem; }
  button { flex:1; padding:.7rem; border-radius:8px; border:1px solid var(--accent); background:var(--accent); color:#fff;
           font:inherit; font-weight:600; cursor:pointer; }
  .error { color:var(--danger); font-size:.9rem; margin:.5rem 0 0; }
</style>
</head>
<body>
<main>
  <div class="brand"><img src="{{ asset('brand/logo.svg') }}" alt="Scribe"></div>
  <div class="card">
    <h1>Welcome to Scribe</h1>
    <p class="muted">A few details to start. You can change all of them later, and add your logo, clinic hours and staff
      once you're in.</p>
    @foreach ($errors->all() as $e)
      <p class="error">{{ $e }}</p>
    @endforeach

    <form method="post" action="{{ route('setup.store') }}">@csrf
      <h2>Your clinic</h2>
      <label for="clinic_name">Clinic name</label>
      <input id="clinic_name" name="clinic_name" value="{{ old('clinic_name') }}" required placeholder="Sampaguita Family Clinic">
      <label for="clinic_address">Address <span>(optional)</span></label>
      <input id="clinic_address" name="clinic_address" value="{{ old('clinic_address') }}" autocomplete="street-address">
      <label for="clinic_phone">Phone <span>(optional)</span></label>
      <input id="clinic_phone" name="clinic_phone" value="{{ old('clinic_phone') }}" autocomplete="tel">

      <h2>You, as printed on prescriptions</h2>
      <label for="name">Name</label>
      <input id="name" name="name" value="{{ old('name') }}" required placeholder="Juan Dela Cruz, MD" autocomplete="name">
      <label for="specialty">Specialty <span>(optional)</span></label>
      <input id="specialty" name="specialty" value="{{ old('specialty') }}" placeholder="Family Medicine">
      <label for="prc_license_no">PRC licence number</label>
      <input id="prc_license_no" name="prc_license_no" value="{{ old('prc_license_no') }}" required maxlength="20">
      <label for="prc_valid_until">PRC licence valid until <span>(optional)</span></label>
      <input id="prc_valid_until" name="prc_valid_until" type="date" value="{{ old('prc_valid_until') }}">

      <h2>Signing in</h2>
      <label for="email">Email</label>
      <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="email">
      <label for="password">Password <span>(12 or more characters)</span></label>
      <input id="password" name="password" type="password" required minlength="12" autocomplete="new-password">
      <label for="password_confirmation">Password again</label>
      <input id="password_confirmation" name="password_confirmation" type="password" required minlength="12" autocomplete="new-password">
      <p class="muted">Write the password down somewhere safe: nobody else can reset it on this PC.</p>

      <div class="row"><button type="submit">Start using Scribe</button></div>
    </form>
  </div>
</main>
</body>
</html>
