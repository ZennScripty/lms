# Multiple subjects aur daily attendance

27 October 2026 update. PWA/PHP application mein yeh features add hue hain. Redis activation aur R2 is change ka hissa nahi hain. Pehle diya gaya Word working guide is update se pehle ka snapshot hai; attendance ke current behavior ke liye yeh note use karein.

## Student aur Teacher ko multiple subjects

- Admin → Subjects → Teacher assignments → **Assign multiple subjects**. Teacher select karein, content/test permission flags choose karein, subjects tick karke **Assign selected subjects** save karein.
- Admin → Enrolments (ya Users → Enrolments) → **Assign multiple subjects**. Student, optional batch aur required subjects choose karein. Naye enrolments active bante hain; har subject ka week one independently open hota hai.
- Existing subject links, permissions aur learning history bulk action se overwrite nahi hote. Saved result added/existing counts batata hai. Existing dropped/completed enrolment reactivate karna ho to uski individual entry edit karein.
- Teacher ke sab assigned subjects visible hain. Student ko sab active/completed eligible courses milte hain. Teacher ke student options mein same student multiple subjects ki wajah se duplicate nahi hota.
- Batch centre validation aur subject permissions server par apply hain. Invalid bulk selection poori transaction rollback karti hai.

## Admin attendance settings

Admin → **Settings → Attendance settings** mein Student attendance aur Staff attendance ke liye alag dropdown hai. Staff mein Teachers aur Admins hain. Dono default **Simple login or open today** hain.

1. **Simple login or open today**: authenticated user pehli baar din mein online page kholta hai to server present attendance aur check-in time save karta hai. Login ke baad dashboard open bhi ismein aata hai. Same day refresh/relogin duplicate nahi banata. Agle din page open karne par nayi daily row banti hai.
2. **Attendance with selfie**: page open par attendance automatic nahi banti. Pending banner/Today attendance se Attendance page kholein → Start camera → Capture selfie → Submit selfie and check in. Valid upload ke baad present aur server check-in time save hote hain.

Selfie camera ke liye browser permission aur localhost/HTTPS access chahiye. Physical mobile camera/permission flow ki device acceptance abhi user ke phone par karni hai. Automated upload tests synthetic camera-size image se hue hain; unhe physical camera test na maanein. Face recognition ya liveness verification implement nahi hai.

Attendance online-only hai. Offline cached page se check-in ya selfie submission nahi banti. Server ki configured date/time use hoti hai; posted date aur device clock par bharosa nahi hota. Image maximum 3 MB, validated JPEG/PNG aur bounded dimensions mein honi chahiye. Server canonical JPEG banakar private local storage mein save karta hai; original metadata/filename retain nahi hote. Camera challenge 5 minute ke liye account/date se bound hai.

## Attendance manage aur corrections

- Admin sab Students aur Staff ki daily attendance manage karega. Teacher sirf apne assigned subjects mein actively enrolled students ki attendance mark/correct karega.
- Attendance person/date basis par hai: multiple subjects hone par bhi ek din ki ek attendance row hai. Per-subject class attendance is change mein nahi hai.
- Existing manual present/absent/leave etc. auto login se overwrite nahi hota. Correction **Edit** se status/remarks par karein; existing record ka person/date change nahi hota. Isse selfie evidence doosre person/day ko transfer nahi hota.
- **View selfie** owner, Admin aur permitted assigned Teacher ko hi milta hai. Teacher correction photo aur original check-in time preserve karti hai. Student history edit nahi kar sakta; apna daily check-in kar sakta hai.
- Mode change existing rows/photos ko replace nahi karta. Agar aaj simple attendance already hai aur mode selfie kiya, aaj ki existing row preserve hogi; bina recorded attendance wale user par naya mode apply hoga.

## Migration aur verification

Local additive migration applied: `php scripts/migrate-attendance-checkin.php`. Existing attendance records preserved; checkin_mode/selfie_path/selfie_sha256 columns, Admin staff type aur two settings keys add hue. Script repeat-safe hai. Selfies `storage/attendance` mein hain, direct HTTP access denied hai aur Git se ignored hain.

Meaningful checks: multiple_subjects 10, attendance_rules 17, attendance_http 34, staff_crud 17, student_rules 24, student HTTP disposable fixture 32 aur service worker 7 assertions passed, total 141. PHP syntax and attendance JavaScript syntax passed. HTTP fixtures/settings restored after tests. Old demo.student.01 ka initial demo password ab match nahi karta; owner ka password reset nahi kiya. Repeatable existing Student HTTP checks ke liye `php tests/student_http_fixture.php` use karein.

Screenshots: `storage/qa/attendance-settings.png` aur `storage/qa/multiple-subjects.png`. Remaining phone acceptance: camera permission allow/deny, capture/retake, valid selfie submit, poor network retry, Hindi UI aur mobile layout.
