# Dashboard attendance In aur Out

27 October 2026. Existing PHP LMS ka attendance UX update; nayi database table ya migration nahi chahiye. Existing `check_in_time`, `check_out_time`, daily unique user/date constraint aur selfie metadata use hote hain.

## User flow

Staff (Teacher/Admin) aur Student dashboard ke top par Today attendance card aata hai. Dashboard open karna attendance **mark nahi karta**; status read-only check hota hai. Yeh pehle wale automatic login check-in ko latest requested explicit-action flow se replace karta hai.

| State | Card par kya dikhega | Action |
| --- | --- | --- |
| Not Checked In | Current server date aur empty timings | Attendance In |
| Checked In | Saved In time aur elapsed duration | Attendance Out |
| Attendance Completed | In, Out aur total duration | In/Out buttons unavailable |
| Attendance recorded | Existing manual attendance without an In timestamp | Teacher/Admin se correction; fabricated check-in/duration nahi |

Simple mode mein Attendance In button direct check-in karta hai. Selfie mode mein isi card par camera permission, capture/retake aur submit available hain. Out ke liye nayi selfie required nahi hai; existing policy sirf In photo ki hai. Admin ke separate Student/Staff mode keys preserve hain. Teacher assigned students ki status/remarks correction aur selfie review pehle ki tarah kar sakta hai.

Save par card AJAX response se wahi update hota hai; dashboard URL nahi badalta. JavaScript unavailable ho to simple In/Out form safe same-page redirect fallback rakhta hai. Selfie capture ko JavaScript aur camera permission chahiye. Offline, expired session, invalid image, camera denial aur connection errors card par feedback/retry dete hain. Offline cached dashboard ke attendance controls fresh authenticated server status milne tak disabled rehte hain.

Status focus, reconnect, page resume aur visible page par once per minute refresh hota hai. Server midnight par current date ka status next refresh mein aata hai. Camera capture ke dauran refresh photo ko silently replace nahi karta. Expired capture challenge par fresh status/retry se camera form renew hota hai.

## Validation aur preserved behavior

- In/Out/status current `Auth::user()` se linked hain. Posted `user_id`, centre, date ya timestamps action ka owner/time select nahi karte.
- Timestamps aur duration application timezone (configured `APP_TIMEZONE`, currently Asia/Kolkata) ke server values se aate hain.
- CSRF required hai. Authenticated account ke user row par transaction lock aur daily unique constraint double clicks/concurrent requests se duplicate records rokta hai.
- Repeated In/Out idempotent no-op hain: JSON `duplicate=true` ke saath saved state return hota hai; pehla In/Out timestamp replace nahi hota. Completed day restart nahi hota.
- Out se pehle today's In required hai. Manual leave/absent row aur original selfie/check-in evidence self actions se overwrite nahi hote. No automatic absent marking, overnight checkout, school schedules ya overtime rules add nahi kiye gaye.
- This repository is a centre/subject based LMS. Separate tenant context/schema, geo-fencing/location permission policy aur school timing/late-entry engine current code mein nahi hain. Inke acceptance checks applicable implementation na hone ke kaaran claim nahi kiye gaye. Existing centre/subject Teacher scope preserve hai; self attendance endpoint kisi doosre person ke record ko mutate nahi karta.

## Layout aur files

Large `page-title` banner Student/Teacher pages, dashboards aur Attendance page se remove hai. Accessible hidden H1, existing topbar breadcrumb, navigation aur mobile safe spacing retained hain. Teal/green theme, amber Out action, readable timing columns aur 44px touch targets existing UI conventions use karte hain.

- `app/Services/AttendanceService.php`: read-only status, duration, camera challenge reuse aur transactional checkout.
- `public/attendance_checkin.php`: existing endpoint mein GET JSON status aur POST In/Out actions; current account only.
- `app/Views/attendance_checkin.php`: shared inline state card, simple form aur camera flow.
- `app/Views/staff_dashboard.php`, `app/Views/student_dashboard.php`: card first in dashboard content.
- `app/Support/ui.php`, `public/assets/css/app.css`: compact title/layout, responsive styling, accessible status feedback.
- `public/assets/js/attendance.js`: same-page save, request locking, status refresh, account-change protection, camera/error handling. Form endpoint uses `getAttribute('action')` so hidden `name=action` cannot shadow form URL.
- `app/bootstrap.php`: old automatic GET check-in removed.
- `app/Views/staff_settings.php`, `app/Config/ui_hi.php`: accurate policy descriptions and Hindi labels.
- `app/Services/ManagementService.php`: checkout time in staff management list.
- `public/sw.js`: asset cache version bumped; attendance API/status/photos remain uncached.

## Verification

133 automated checks passed in this update: attendance rules 27; attendance HTTP 50; Student HTTP disposable fixture 32; staff CRUD 17; service worker 7. PHP syntax and attendance JS syntax passed. Tests cover self ownership, forged IDs/timestamps, no implicit attendance on open, Out-before-In, idempotence, duration, protected manual attendance, selfie upload/policy/permissions, dashboard states, removed title and retained learning/access flows. Temporary HTTP test settings restored and fixtures cleaned.

Actual browser: Staff In → Out → Completed and Student Out updated in place on dashboard. Student selfie prompt inspected without opening user's physical camera. Desktop, tablet 768×1024 and mobile 375×812 layouts checked; Hindi labels visually checked. Screenshots under `storage/qa/dashboard-attendance-in.png`, `dashboard-attendance-mobile.png` and `dashboard-attendance-mobile-hi.png`.

Remaining device acceptance: actual phone camera permission allow/deny, camera capture/retake and poor-network submission on hardware. Synthetic image upload acceptance passed; no face recognition/liveness claim. Multi-tenant, geo/timing rules require a separately agreed project scope if needed.
