# COLM Registrar – Student Document Management System

Plain PHP + MySQL + JavaScript. No framework, no Composer, no npm, **no paid service and no API key**.
Needs **PHP 8.0+** (`pdo_mysql`, `curl`, `fileinfo`, `mbstring`, `gd`; `intl` optional) and **MySQL/MariaDB** (all in XAMPP; turn on `extension=gd` in `php.ini` if it is off).

## Roles

| Role | Can do |
|---|---|
| **Student** | Own profile, catalog, requests (stages 1-5), uploads with verification, QR code, payment information, chatbot |
| **Registrar** | Students (add/edit/CSV import), catalog, review and approve/decline documents, process requests, chatbot |
| **Admin** | Everything the registrar does **plus** creating accounts (Admin/Registrar/Cashier) and the AI tools check |
| **Cashier** | **Payments only**: Payment Dashboard, Pending Payments (Record Payment + student payment information), Payment History / receipts |

The role is checked in PHP in every `api/` file, not only by hiding menu items. A cashier who types the address of a restricted `api/` file in the browser is sent back to the payment page; other requests get `403`. The registrar may review the documents, but cannot start processing a request with a fee until the cashier records its full payment.

A student can have only one active request per document type. The same document becomes available again after its request is completed or rejected; requests for other document types are unaffected.

## Folder guide

| Folder / file | What is inside |
|---|---|
| `frontend/` | `login.html`, `student.html`, `manage.html` (admin, registrar, cashier), `css/style.css`, `js/`. `admin.html`, `registrar.html`, `cashier.html`, `index.html` only redirect (old pages of the first version) |
| `api/` | One PHP file per feature: `login.php`, `requests.php`, `verify.php`, `payments.php`, `qr.php`, `chat.php`, `students.php`, `users.php`, `documents.php`, `photo.php`, `file.php`, `notifications.php`, `ai_test.php` |
| `backend/` | Shared code: `database.php`, `auth.php`, `helpers.php`, `files.php`, `workflow.php`, `ai.php` (document check + chatbot), `names.php`, `students.php` (school structure + CSV) |
| `database/` | `install.sql`, `install.php` (installs **and upgrades** an older database) |
| `tests/` | `smoke_test.php`, `ai_parse_test.php`, `names_test.php`, `students_test.php` |

## Install on XAMPP

1. Copy the folder to `C:\xampp\htdocs\new-ui-main`, start **Apache** and **MySQL**.
2. Copy `.env.example` to `.env`.
3. `php database/install.php` – creates or upgrades the database (it renames the old role `personnel` to `cashier`, adds the new columns/tables, keeps all data).
4. Open `http://localhost/new-ui-main/`. Demo accounts (password `Password123!`): `2026-00001` (student), `admin`, `registrar`, `cashier`.
5. Install the two free AI tools below, then log in as admin and press **Settings > Check AI tools**.

## Free AI tools

**Document checking – Tesseract OCR (free, runs on your computer).**
* Windows: install *Tesseract OCR* (the UB Mannheim installer). Put its path in `.env` if it is not in the PATH: `TESSERACT_PATH=C:\Program Files\Tesseract-OCR\tesseract.exe`.
* PDF files also need *poppler* (`pdftotext`, `pdftoppm`). Download the Windows build, and put its `bin` folder in `.env`: `POPPLER_PATH=C:\poppler\Library\bin`. Pictures (JPG/PNG) work without poppler.
* Linux: `sudo apt install tesseract-ocr poppler-utils`.

**Chatbot – Ollama (free, runs on your computer).** Install from https://ollama.com, then run once: `ollama pull llama3.2:3b` (about 2 GB; a weaker computer can use `llama3.2:1b` – change `OLLAMA_MODEL` in `.env`). Ollama must be running while the chatbot is used.

No key, no internet and no account are needed after the downloads. If a tool is missing or stopped the website keeps working and shows a clear message.

## Stage 3 – document pre-check

Each file is sent to `api/verify.php` for an initial automated pre-check as soon as it is chosen. The student sees **✓ File uploaded: name.pdf** and **Document pre-check: In progress...**, then one of:

| Result | Student sees | Can continue? |
|---|---|---|
| VERIFIED | "Document pre-check passed." | yes; the registrar still reviews the request |
| DECLINED | "Document pre-check: Not accepted" with a reason (too blurry, too dark, could not be read, not the required document, name does not match ...) | **no** – Upload Again |
| NEEDS CORRECTION | "Document pre-check: Needs correction" with a reason | **no** – Upload Again |
| PENDING | "Document pre-check incomplete" with a reason | **no** – Try Again |

How a file is checked (`backend/ai.php`): 1) picture quality with PHP (too small, too dark, blank, blurry; PDF damaged/locked); 2) Tesseract reads the document text; 3) enough clear text? 4) does it contain at least two configured requirement keywords and a document-specific keyword (for example, "transcript" for the TOR request or "clearance" for its clearance requirement)? Generic words such as "request" and "form" alone do not pass; 5) when the requirement asks for a name, does the name read from the document match the first and last name on the student's account? The uploaded filename alone is never used as proof of document type or identity.
Removing the file clears the status. The **Review Request** button is disabled while a file is missing, being checked, declined, needs correction or pending. This is an initial pre-check; the registrar makes the final review.

It cannot be skipped: `api/requests.php` only accepts verification numbers that belong to the logged-in student, that are VERIFIED and were not used yet. Refreshing, editing the JavaScript or sending your own POST does not help. The registrar still approves or declines every document afterwards.

## Stage 5 – QR code

`api/qr.php` returns the QR text of a request; if the request has none yet it creates one (`COLM|tracking number|token`), **saves it in `requests.qr_code`** and returns it. Asking again always returns the same QR, so there are no duplicates and it is still there after a refresh. The picture is drawn in the browser by our own small encoder `frontend/js/qr.js` (no internet / CDN needed). If it fails: "QR code generation failed. Please try again." with a Try again button. Students also see the QR when they open a request in My Requests.

## School Level / Course / Year / Section

The list lives in `backend/students.php` (`school_structure()`). The Add/Edit Student form gets it from `api/students.php?action=structure`, so the dropdowns and the PHP check use the same list. College: BSTM Year 1-4 with 3/2/4/2 sections. Senior High: Grade 11 STEM 2, HUMSS 3; Grade 12 STEM 1, HUMSS 2. High School: Grade 7/8/9/10 with 4/3/5/4 sections. CSV import uses the same rules (course must be BSTM, STEM, HUMSS or HIGH SCHOOL).

## Deploy checklist

* `.env`: `APP_ENV=production`, real `DB_*`, `INITIAL_ADMIN_PASSWORD` (10+ characters). Never commit `.env`.
* Run `php database/install.php` once on the server. Change the demo passwords.
* `UPLOAD_DIR` outside the web folder. Use HTTPS. Apache needs `AllowOverride All` for the `.htaccess` files.
* The server needs Tesseract (and poppler for PDF) installed; Ollama must run on a computer the server can reach (`OLLAMA_URL`).
* If `api/` is not next to `frontend/`, change `API_URL` in `frontend/js/api.js`.

## Tests

```
php tests/names_test.php
php tests/students_test.php
php tests/ai_parse_test.php
php tests/smoke_test.php http://localhost/new-ui-main/api/
```
`smoke_test.php` needs the database installed, Apache running, GD and Tesseract installed. It covers login and role blocking (cashier is payment-only), Stage 3 (blurry, dark, other person's document, tampered requests), the QR code, payments and receipts, accounts for all three roles, the school structure and CSV import, and the chatbot's friendly error.
