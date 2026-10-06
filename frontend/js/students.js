// Admin/registrar: the Students page - list, add, edit, delete, reset password and CSV import.
(function () {
  const body = document.getElementById("studentsBody");
  const courseFilter = document.getElementById("studentCourseFilter");
  let page = 1;
  let searchTimer = null;
  let students = [];

  // ---------- List ----------
  async function loadStudents() {
    const params = { action: "list", page };
    if (courseFilter.value) params.course = courseFilter.value;
    const search = document.getElementById("searchInput").value.trim();
    if (search) params.q = search;
    try {
      const data = await Api.get("students.php", params);
      students = data.students;
      const selected = courseFilter.value;
      courseFilter.innerHTML = '<option value="">All courses</option>' + data.courses.map(c => `<option>${escapeHtml(c)}</option>`).join("");
      courseFilter.value = selected;
      body.innerHTML = data.students.length ? data.students.map(s => `
        <tr><td>${escapeHtml(s.student_no || s.username)}</td><td>${escapeHtml(s.full_name)}</td><td>${escapeHtml(s.course || "")}<br><small>${escapeHtml(s.school_level || "")}</small></td>
          <td>${escapeHtml(s.year_label || "")} ${escapeHtml(s.section || "")}</td><td>${escapeHtml(s.email || "")}</td>
          <td>${s.is_active ? "Active" : "Disabled"}${s.must_change_password ? '<br><small>Default password</small>' : ""}</td>
          <td class="row-actions">
            <button class="request-table-action" type="button" data-profile="${s.id}">View Profile</button>
            <button class="request-table-action" type="button" data-edit="${s.id}">Edit</button>
            <button class="request-table-action" type="button" data-reset="${s.id}">Reset password</button>
            <button class="request-table-action danger" type="button" data-delete="${s.id}">Delete</button>
          </td></tr>`).join("")
        : '<tr><td colspan="7" class="requests-empty">No students found. Use Add Student or Import CSV.</td></tr>';
    } catch (error) {
      body.innerHTML = `<tr><td colspan="7" class="requests-empty">${escapeHtml(error.message)}</td></tr>`;
    }
  }

  // ---------- Credentials window (shown once) ----------
  function showCredentials(title, list) {
    const rows = list.map(c => `<tr><td>${escapeHtml(c.full_name)}</td><td><code>${escapeHtml(c.username)}</code></td><td><code>${escapeHtml(c.password)}</code></td></tr>`).join("");
    const modal = Portal.openModal(`
      <h2>${escapeHtml(title)}</h2>
      <div class="notice notice-warning">Write these down or download them now. For safety the passwords are stored only as hashes, so they <strong>cannot be shown again</strong>. The default password is <strong>Password123!</strong> and each student must choose a new password at the first login.</div>
      <div class="requests-table-wrap"><table class="detail-table"><thead><tr><th>Name</th><th>Username (Student ID)</th><th>Default password</th></tr></thead><tbody>${rows}</tbody></table></div>
      <div class="detail-buttons"><button class="request-next-btn" type="button" id="downloadCredentials">Download credentials (CSV)</button>
        <button class="request-back-btn" type="button" data-close-modal>Close</button></div>`, true);
    modal.querySelector("#downloadCredentials").addEventListener("click", () => {
      const csv = "full_name,username,default_password\r\n" + list.map(c => [c.full_name, c.username, c.password].map(csvCell).join(",")).join("\r\n") + "\r\n";
      downloadText(csv, "student_credentials.csv");
    });
  }
  const csvCell = value => `"${String(value).replace(/"/g, '""')}"`;
  function downloadText(text, filename) {
    const link = document.createElement("a");
    link.href = URL.createObjectURL(new Blob([text], { type: "text/csv;charset=utf-8" }));
    link.download = filename;
    link.click();
    URL.revokeObjectURL(link.href);
  }

  // ---------- Add / edit one student ----------
  // The School Level / Course / Year Level / Section lists come from PHP (api/students.php?action=structure),
  // the same list PHP uses to check the form, so only real combinations can be chosen and saved.
  let structure = null;
  async function loadStructure() {
    if (!structure) structure = (await Api.get("students.php", { action: "structure" })).levels;
    return structure;
  }

  // Fills a <select> with options. `items` = list of [value, text]. The first option is the "Select ..." hint.
  function fillSelect(select, hint, items, selectedValue) {
    select.innerHTML = `<option value="">${hint}</option>` + items.map(([value, text]) => `<option value="${escapeHtml(String(value))}">${escapeHtml(text)}</option>`).join("");
    select.value = selectedValue !== undefined && selectedValue !== null ? String(selectedValue) : "";
    select.disabled = items.length === 0;
  }

  async function studentForm(student) {
    let levels;
    try { levels = await loadStructure(); } catch (error) { Portal.toast(error.message, "error"); return; }
    const s = student || {};
    const editing = !!student;
    const modal = Portal.openModal(`
      <h2>${editing ? "Edit Student" : "Add Student"}</h2>
      <form class="student-form" novalidate>
        <label>Student ID number<input class="request-text-input" name="student_id" value="${escapeHtml(s.student_no || "")}" ${editing ? "disabled" : "required"} maxlength="30" placeholder="e.g. 2026-10001"></label>
        <label>Full name<input class="request-text-input" name="full_name" value="${escapeHtml(s.full_name || "")}" required maxlength="120"></label>
        <label>Email<input class="request-text-input" name="email" type="email" value="${escapeHtml(s.email || "")}" maxlength="120"></label>
        <label>School Level<select class="request-text-input" name="school_level" required></select></label>
        <label>Course / Department<select class="request-text-input" name="course" required></select></label>
        <label>Year Level<select class="request-text-input" name="year" required></select></label>
        <label>Section<select class="request-text-input" name="section" required></select></label>
        <label>Contact number<input class="request-text-input" name="contact_number" value="${escapeHtml(s.contact_no || "")}" maxlength="20"></label>
        <p class="form-message error" role="alert"></p>
        <div class="detail-buttons"><button class="request-next-btn" type="submit">${editing ? "Save changes" : "Create student"}</button>
          <button class="request-back-btn" type="button" data-close-modal>Cancel</button></div>
      </form>`);
    const form = modal.querySelector("form");
    const level = form.elements.school_level, course = form.elements.course, year = form.elements.year, section = form.elements.section;

    // each list depends on the one before it
    const courseList = () => (levels.find(l => l.level === level.value) || { courses: [] }).courses;
    const yearList = () => (courseList().find(c => c.name === course.value) || { years: [] }).years;
    const sectionCount = () => (yearList().find(y => String(y.year) === year.value) || { sections: 0 }).sections;

    const fillCourses = chosen => fillSelect(course, "Select course", courseList().map(c => [c.name, c.name]), chosen);
    const fillYears = chosen => fillSelect(year, "Select year level", yearList().map(y => [y.year, y.label]), chosen);
    const fillSections = chosen => fillSelect(section, "Select section", Array.from({ length: sectionCount() }, (_, i) => ["Section " + (i + 1), "Section " + (i + 1)]), chosen);

    fillSelect(level, "Select school level", levels.map(l => [l.level, l.level]), s.school_level);
    fillCourses(s.course); fillYears(s.year_level); fillSections(s.section);

    // When something changes, everything after it is reset and filled again
    level.addEventListener("change", () => { fillCourses(""); fillYears(""); fillSections(""); });
    course.addEventListener("change", () => { fillYears(""); fillSections(""); });
    year.addEventListener("change", () => fillSections(""));
    // High School has only one course, so choose it for the student
    level.addEventListener("change", () => {
      if (courseList().length === 1) { course.value = courseList()[0].name; course.dispatchEvent(new Event("change")); }
    });

    form.addEventListener("submit", async event => {
      event.preventDefault();
      const values = Object.fromEntries(new FormData(form));
      if (editing) values.id = s.id;
      const message = form.querySelector(".form-message");
      message.textContent = "";
      if (!values.school_level || !values.course || !values.year || !values.section) { message.textContent = "Please choose the School Level, Course, Year Level and Section."; return; }
      await Portal.busy(form.querySelector("button[type=submit]"), "Saving...", async () => {
        try {
          const result = await Api.postJson("students.php", values, { action: editing ? "update" : "create" });
          Portal.closeModal();
          loadStudents();
          if (!editing) showCredentials("Student account created", [result.credentials]);
          else Portal.toast("Student saved.", "success");
        } catch (error) { message.textContent = error.message; }
      });
    });
  }

  // ---------- CSV import ----------
  function importWindow() {
    const modal = Portal.openModal(`
      <h2>Import Students from CSV</h2>
      <p>Each student gets an account. The <strong>Student ID is the username</strong> and the default password is <strong>Password123!</strong>.</p>
      <p><a href="${API_URL}students.php?action=template" id="sampleCsv">Download the sample CSV</a> (columns: student_id, full_name, email, course, year, section, contact_number). The course must be BSTM, STEM, HUMSS or HIGH SCHOOL; year and section must exist for that course (for example BSTM, 3, Section 4).</p>
      <input type="file" id="csvFile" accept=".csv,text/csv" aria-label="CSV file">
      <div id="csvResult" class="csv-result"></div>
      <div class="detail-buttons">
        <button class="request-back-btn" type="button" id="csvCheck">Check file</button>
        <button class="request-next-btn" type="button" id="csvImport" disabled>Import students</button>
        <button class="request-back-btn" type="button" data-close-modal>Cancel</button>
      </div>`, true);
    const fileInput = modal.querySelector("#csvFile");
    const result = modal.querySelector("#csvResult");
    const importButton = modal.querySelector("#csvImport");

    const send = dryRun => {
      const form = new FormData();
      form.append("csv", fileInput.files[0]);
      if (dryRun) form.append("dry_run", "1");
      return Api.postForm("students.php", form, { action: "import" });
    };
    const failedTable = failed => failed.length ? `
      <h3>Rows with problems (${failed.length}) — these are NOT imported</h3>
      <div class="requests-table-wrap"><table class="detail-table"><thead><tr><th>Line</th><th>Student ID</th><th>Name</th><th>Problem</th></tr></thead><tbody>
      ${failed.map(f => `<tr><td>${f.line}</td><td>${escapeHtml(f.student_id)}</td><td>${escapeHtml(f.full_name)}</td><td>${f.errors.map(escapeHtml).join("<br>")}</td></tr>`).join("")}</tbody></table></div>` : "";

    fileInput.addEventListener("change", () => { importButton.disabled = true; result.innerHTML = ""; });

    modal.querySelector("#csvCheck").addEventListener("click", async event => {
      if (!fileInput.files[0]) { Portal.toast("Choose a CSV file first.", "error"); return; }
      await Portal.busy(event.currentTarget, "Checking...", async () => {
        try {
          const data = await send(true);
          result.innerHTML = `<div class="notice ${data.failed.length ? "notice-warning" : "notice-ok"}"><strong>${data.valid_count}</strong> student(s) are ready to import${data.failed.length ? `, <strong>${data.failed.length}</strong> row(s) have problems` : ""}.</div>
            ${data.preview.length ? `<h3>Preview</h3><div class="requests-table-wrap"><table class="detail-table"><thead><tr><th>Student ID</th><th>Name</th><th>Course</th><th>Year</th><th>Section</th></tr></thead><tbody>
            ${data.preview.map(p => `<tr><td>${escapeHtml(p.student_id)}</td><td>${escapeHtml(p.full_name)}</td><td>${escapeHtml(p.course)}</td><td>${escapeHtml(String(p.year))}</td><td>${escapeHtml(p.section)}</td></tr>`).join("")}</tbody></table></div>` : ""}
            ${failedTable(data.failed)}`;
          importButton.disabled = data.valid_count === 0;
        } catch (error) { result.innerHTML = `<div class="notice notice-warning">${escapeHtml(error.message)}</div>`; importButton.disabled = true; }
      });
    });

    importButton.addEventListener("click", async () => {
      await Portal.busy(importButton, "Importing...", async () => {
        try {
          const data = await send(false);
          loadStudents();
          const credentials = data.imported.map(i => ({ full_name: i.full_name, username: i.username, password: i.password }));
          if (credentials.length) {
            showCredentials(`${credentials.length} student account(s) created`, credentials);
            if (data.failed.length) {
              const box = document.querySelector("#modalBackdrop .modal-body");
              box.insertAdjacentHTML("beforeend", failedTable(data.failed));
            }
          } else {
            result.innerHTML = `<div class="notice notice-warning">No student was imported.</div>${failedTable(data.failed)}`;
          }
        } catch (error) { Portal.toast(error.message, "error"); }
      });
    });
  }

  // ---------- Buttons ----------
  document.getElementById("addStudentBtn").addEventListener("click", () => studentForm(null));
  document.getElementById("importCsvBtn").addEventListener("click", importWindow);
  courseFilter.addEventListener("change", () => { page = 1; loadStudents(); });

  body.addEventListener("click", async event => {
    const button = event.target.closest("button[data-profile], button[data-edit], button[data-reset], button[data-delete]");
    if (!button) return;
    const id = Number(button.dataset.profile || button.dataset.edit || button.dataset.reset || button.dataset.delete);
    const student = students.find(s => s.id === id);
    if (button.dataset.profile) window.openStudentProfile(id);
    else if (button.dataset.edit) studentForm(student);
    else if (button.dataset.reset) {
      if (!window.confirm(`Create a new default password for ${student.full_name}? The old password will stop working.`)) return;
      await Portal.busy(button, "Saving...", async () => {
        const data = await Api.postJson("students.php", { id }, { action: "reset_password" });
        showCredentials("New default password", [data.credentials]);
        loadStudents();
      });
    } else if (button.dataset.delete) {
      if (!window.confirm(`Delete ${student.full_name} (${student.student_no || student.username})? This cannot be undone.`)) return;
      await Portal.busy(button, "Deleting...", async () => {
        await Api.postJson("students.php", { id }, { action: "delete" });
        Portal.toast("Student deleted.", "success");
        loadStudents();
      });
    }
  });

  document.getElementById("searchInput").addEventListener("input", () => {
    if (Portal.currentPage !== "Students") return;
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => { page = 1; loadStudents(); }, 300);
  });
  document.addEventListener("page:change", event => { if (event.detail === "Students") loadStudents(); });
})();
