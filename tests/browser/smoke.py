# Browser smoke test (fictional demo data: php artisan scribe:demo). Run on karokrok, from the project folder:
#   docker compose exec -T app php artisan cache:clear   # sign-ins are limited to 5 a minute
#   docker run --rm --network host -v "$PWD/tests/browser:/t" -v "$PWD/storage/logs:/logs:ro" -e BASE=http://127.0.0.1:8100 -e PW="$SCRIBE_DEMO_PASSWORD" \
#     mcr.microsoft.com/playwright/python:v1.55.0-noble sh -c "pip install -q playwright==1.55.0 && python /t/smoke.py"
import os
from playwright.sync_api import sync_playwright

BASE, PW = os.environ["BASE"], os.environ["PW"]
os.makedirs("/t/shots", exist_ok=True)


def login(page, email):
    page.goto(f"{BASE}/admin/login")
    page.fill("input[type=email]", email)
    page.fill("input[type=password]", PW)
    page.click("button[type=submit]")
    try:
        page.wait_for_url(lambda u: "/login" not in u, timeout=20000)
    except Exception:
        page.screenshot(path="/t/shots/login_failed.png", full_page=True)
        raise


def check(page, path, expect, shot):
    r = page.goto(f"{BASE}{path}")
    page.wait_for_load_state("networkidle")
    ok = r.status == 200 and expect in page.content()
    page.screenshot(path=f"/t/shots/{shot}.png", full_page=True)
    print(("OK  " if ok else "BAD ") + f"{r.status} {path} ({expect!r})")
    return ok


results = []
with sync_playwright() as p:
    b = p.chromium.launch()
    for email, checks in [
        ("demo.admin@example.test", [("/admin/sampaguita", "Doctors with verified licences", "01_dashboard"),
                                     ("/admin/sampaguita/members", "Joy Mendoza", "02_staff"),
                                     ("/admin/sampaguita/doctors", "Villanueva", "03_doctors"),
                                     ("/admin/sampaguita/profile", "Clinic hours", "04_clinic_details")]),
        ("demo.doctor@example.test", [("/admin/narra/doctors", "Villanueva", "05_doctor_other_clinic")]),
        ("demo.staff@example.test", [("/admin/sampaguita/members", "Liza Santos", "06_staff_view")]),
    ]:
        ctx = b.new_context(viewport={"width": 1280, "height": 900})
        page = ctx.new_page()
        login(page, email)
        results += [check(page, *c) for c in checks]
        if email == "demo.staff@example.test":
            r = page.goto(f"{BASE}/admin/narra/members")
            print(("OK  " if r.status == 404 else "BAD ") + f"{r.status} staff kept out of another clinic")
            results.append(r.status == 404)
        if email == "demo.admin@example.test":
            admin_page = page                            # reused below (sign-ins are limited to 5 a minute)
        else:
            ctx.close()
    # invitation: the clinic admin invites, the new person opens the emailed link (dev mail goes to the log)
    import re, time, glob
    stamp = str(int(time.time()))
    email = f"invitee.{stamp}@example.test"
    page = admin_page
    page.set_viewport_size({"width": 390, "height": 844})
    results.append(check(page, "/admin/sampaguita/doctors", "Villanueva", "07_doctors_phone"))
    page.set_viewport_size({"width": 1280, "height": 900})
    page.goto(f"{BASE}/admin/sampaguita/members")
    page.get_by_role("button", name="Invite", exact=True).first.click()
    modal = page.locator(".fi-modal-window").filter(has_text="Send invitation")
    modal.locator("input[type=email]").fill(email)
    modal.locator("select").select_option("STAFF")
    page.get_by_role("button", name="Send invitation").click()
    page.get_by_text(f"Invitation sent to {email}").wait_for(timeout=15000)
    log = open("/logs/laravel.log", encoding="utf-8", errors="replace").read()
    block = log[log.rfind(email):]
    link = re.search(r"/invitations/[A-Za-z0-9]{48}", block)
    ctx = b.new_context(viewport={"width": 390, "height": 844})
    page = ctx.new_page()
    page.goto(f"{BASE}{link.group(0)}")
    page.screenshot(path="/t/shots/08_invitation_phone.png", full_page=True)
    page.fill("#name", "Invited Person")
    page.fill("#password", PW)
    page.fill("#password_confirmation", PW)
    page.click("button[type=submit]")
    try:
        page.wait_for_url(lambda u: u.rstrip("/").endswith("/admin/sampaguita"), timeout=20000)
    except Exception:
        print("stuck at", page.url)
        page.screenshot(path="/t/shots/invitation_failed.png", full_page=True)
        raise
    page.screenshot(path="/t/shots/09_after_accepting.png", full_page=True)
    ok = "Sampaguita" in page.content()
    print(("OK  " if ok else "BAD ") + "invitation: new person created an account and joined")
    results.append(ok)
    ctx.close()

    # prescribing: the doctor opens the patient list and a draft, issues it, and downloads the PDF (phases 2 and 3).
    # A repeat at the end leaves a new draft, so the test can run again.
    ctx = b.new_context(viewport={"width": 1280, "height": 900})
    page = ctx.new_page()
    login(page, "demo.doctor@example.test")
    results.append(check(page, "/admin/sampaguita/patients", "Dizon", "10_patients"))
    results.append(check(page, "/admin/sampaguita/medications", "Amoxicillin", "11_medicines"))
    page.goto(f"{BASE}/admin/sampaguita/prescriptions")
    page.wait_for_load_state("networkidle")
    page.locator("tr", has_text="Dizon").filter(has_text="Draft").first.click()
    page.wait_for_url(lambda u: "/prescriptions/" in u and not u.endswith("/prescriptions"), timeout=15000)
    page.wait_for_load_state("networkidle")
    page.screenshot(path="/t/shots/12_draft.png", full_page=True)
    page.get_by_role("button", name="Issue", exact=True).click()
    page.locator(".fi-modal-window").get_by_role("button", name="Confirm").click()
    page.get_by_text("Issued SAMPAGUITA-").first.wait_for(timeout=20000)
    page.wait_for_load_state("networkidle")
    page.screenshot(path="/t/shots/13_issued.png", full_page=True)
    href = page.locator("a", has_text="Download PDF").first.get_attribute("href")
    r = page.request.get(href)
    ok = r.status == 200 and r.headers.get("content-type") == "application/pdf" and r.body()[:4] == b"%PDF"
    print(("OK  " if ok else "BAD ") + f"{r.status} issued and downloaded {href}")
    results.append(ok)
    open("/t/shots/14_prescription.pdf", "wb").write(r.body())
    page.get_by_role("button", name="More").click()
    page.get_by_role("menuitem", name="Repeat").click() if page.get_by_role("menuitem", name="Repeat").count() else page.get_by_text("Repeat", exact=True).click()
    page.locator(".fi-modal-window").get_by_role("button", name="Confirm").click()
    page.wait_for_url(lambda u: u.endswith("/edit"), timeout=15000)
    print("OK  repeat made a new draft for the next run")
    page.set_viewport_size({"width": 390, "height": 844})
    page.wait_for_load_state("networkidle")
    page.screenshot(path="/t/shots/15_builder_phone.png", full_page=True)
    ctx.close()
    b.close()
print("ALL OK" if all(results) else "SOME FAILED")
