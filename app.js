// princar.ro – interacțiunile paginii: ferestre (modale), formulare, întrebări frecvente, meniu mobil
(function () {
  document.addEventListener("DOMContentLoaded", function () {
    // ---------- Ferestre: orice element cu data-modal="id" deschide <dialog id="id"> ----------
    var openModal = function (id) {
      var d = document.getElementById(id);
      if (!d || typeof d.showModal !== "function") return;
      document.querySelectorAll("dialog[open]").forEach(function (o) { if (o !== d) o.close(); });
      if (!d.open) d.showModal();
      var f = d.querySelector(".lead-body input, .lead-body textarea");
      if (f && d.querySelector(".lead-body:not([hidden])")) f.focus();
    };
    document.addEventListener("click", function (e) {
      var t = e.target.closest("[data-modal]");
      if (t) { e.preventDefault(); openModal(t.dataset.modal); return; }
      if (e.target.closest("[data-close]")) { var d = e.target.closest("dialog"); if (d) d.close(); return; }
      if (e.target.tagName === "DIALOG") e.target.close(); // click în afara ferestrei
    });
    // Linkurile directe (ex. princar.ro/#termeni) deschid fereastra potrivită
    var fromHash = function () { var id = location.hash.slice(1); if (/^(termeni|confidentialitate|contact)$/.test(id)) openModal(id); };
    fromHash(); window.addEventListener("hashchange", fromHash);

    // ---------- Trimitere comună pentru formulare ----------
    var send = function (form, url, data, errBox, onOk) {
      var btn = form.querySelector("button[type=submit]");
      btn.disabled = true;
      fetch(url, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(data) })
        .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
        .then(function (res) {
          if (!res.ok) { errBox.textContent = res.error || "Nu am putut trimite. Încearcă din nou sau scrie-ne la contact@princar.ro."; return; }
          onOk();
        })
        .catch(function () { errBox.textContent = "Nu am putut trimite. Verifică internetul și încearcă din nou."; })
        .then(function () { btn.disabled = false; });
    };

    // ---------- Formularul de cerere (se deschide din orice buton data-lead) ----------
    var dlg = document.getElementById("lead");
    if (dlg) {
      var form = dlg.querySelector("form"), body = dlg.querySelector(".lead-body"), ok = dlg.querySelector(".lead-ok"), err = dlg.querySelector(".lead-err");
      var range = dlg.querySelector("#l-suma"), out = dlg.querySelector("#l-suma-out");
      var nf = new Intl.NumberFormat("ro-RO");
      range.oninput = function () { out.textContent = nf.format(+range.value) + " lei"; };
      document.querySelectorAll("[data-lead]").forEach(function (b) {
        b.addEventListener("click", function () { body.hidden = false; ok.hidden = true; err.textContent = ""; dlg.showModal(); dlg.querySelector("#l-nume").focus(); });
      });
      dlg.querySelector(".lead-x").onclick = function () { dlg.close(); };
      dlg.querySelector(".lead-close").onclick = function () { dlg.close(); };
      form.addEventListener("input", function () { err.textContent = ""; });
      form.addEventListener("submit", function (e) {
        e.preventDefault();
        var nume = form.nume.value.trim(), tel = form.telefon.value.replace(/\s/g, ""), gdpr = form.querySelector(".consent input").checked;
        if (!nume) { err.textContent = "Completează numele și prenumele."; form.nume.focus(); return; }
        if (!/^(\+4)?07\d{8}$/.test(tel)) { err.textContent = "Introdu un număr de telefon valid, de forma 07xx xxx xxx."; form.telefon.focus(); return; }
        if (!gdpr) { err.textContent = "Bifează acordul ca să te putem suna."; return; }
        send(form, "api/lead.php", { nume: nume, telefon: tel, suma: +range.value, consimtamant: gdpr, website: form.website ? form.website.value : "", sursa: location.pathname + location.search }, err,
          function () { body.hidden = true; ok.hidden = false; form.reset(); range.oninput(); });
      });
    }

    // ---------- Formularul de contact ----------
    var cd = document.getElementById("contact");
    if (cd) {
      var cf = cd.querySelector("form"), cBody = cd.querySelector(".lead-body"), cOk = cd.querySelector(".lead-ok"), cErr = cd.querySelector(".lead-err");
      cd.addEventListener("close", function () { cBody.hidden = false; cOk.hidden = true; cErr.textContent = ""; });
      cf.addEventListener("input", function () { cErr.textContent = ""; });
      cf.addEventListener("submit", function (e) {
        e.preventDefault();
        var nume = cf.nume.value.trim(), email = cf.email.value.trim(), mesaj = cf.mesaj.value.trim(), gdpr = cf.gdpr.checked;
        if (!nume) { cErr.textContent = "Completează numele."; cf.nume.focus(); return; }
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { cErr.textContent = "Introdu o adresă de e-mail validă."; cf.email.focus(); return; }
        if (mesaj.length < 5) { cErr.textContent = "Scrie-ne un mesaj."; cf.mesaj.focus(); return; }
        if (!gdpr) { cErr.textContent = "Bifează acordul pentru prelucrarea datelor."; return; }
        send(cf, "api/contact.php", { nume: nume, email: email, mesaj: mesaj, gdpr: gdpr, website: cf.website.value }, cErr,
          function () { cBody.hidden = true; cOk.hidden = false; cf.reset(); });
      });
    }

    // ---------- Întrebări frecvente: doar una deschisă odată ----------
    var faqs = document.querySelectorAll(".faq4 details");
    faqs.forEach(function (d) {
      d.addEventListener("toggle", function () {
        if (d.open) faqs.forEach(function (o) { if (o !== d) o.open = false; });
      });
    });

    // ---------- Meniu mobil ----------
    var mb = document.querySelector(".menu-btn"), nav = document.querySelector(".nav");
    if (mb && nav) {
      mb.onclick = function () { var o = nav.classList.toggle("open"); mb.setAttribute("aria-expanded", o ? "true" : "false"); };
      nav.addEventListener("click", function (e) { if (e.target.closest("a")) { nav.classList.remove("open"); mb.setAttribute("aria-expanded", "false"); } });
    }
  });
})();
