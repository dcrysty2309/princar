// Culoarea finală: portocaliu
(function () {
  document.documentElement.setAttribute("data-theme", "portocaliu");

  document.addEventListener("DOMContentLoaded", function () {
    // Hero animat: numere care cresc și pașii care se bifează pe rând
    var fmtN = new Intl.NumberFormat("ro-RO");
    var calm = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    document.querySelectorAll(".hero-art [data-count]").forEach(function (el) {
      var end = +el.dataset.count, t0 = null, dur = 1400;
      if (calm) { el.textContent = fmtN.format(end); return; }
      var tick = function (t) {
        if (!t0) t0 = t;
        var k = Math.min(1, (t - t0) / dur), e = 1 - Math.pow(1 - k, 3);
        el.textContent = fmtN.format(Math.round(end * e));
        if (k < 1) requestAnimationFrame(tick);
      };
      setTimeout(function () { requestAnimationFrame(tick); }, 400);
    });
    var steps = document.querySelectorAll(".anim .steps li");
    if (steps.length) {
      var i = 0;
      var show = function () {
        steps.forEach(function (li, n) { li.className = n < i ? "on" : (n === i ? "now" : ""); });
        i = i >= steps.length ? 0 : i + 1;
      };
      show();
      if (!calm) setInterval(show, 1500);
      else steps.forEach(function (li) { li.className = "on"; });
    }

    // Formularul scurt: un singur buton deschide fereastra
    var dlg = document.getElementById("lead");
    if (dlg) {
      var form = dlg.querySelector("form"), body = dlg.querySelector(".lead-body"), ok = dlg.querySelector(".lead-ok"), err = dlg.querySelector(".lead-err");
      var range = dlg.querySelector("#l-suma"), out = dlg.querySelector("#l-suma-out");
      var nf = new Intl.NumberFormat("ro-RO");
      range.oninput = function () { out.textContent = nf.format(+range.value) + " lei"; };
      document.querySelectorAll("[data-lead]").forEach(function (b) {
        b.addEventListener("click", function () { body.hidden = false; ok.hidden = true; err.textContent = ""; dlg.showModal(); dlg.querySelector("#l-nume").focus(); });
      });
      var close = function () { dlg.close(); };
      dlg.querySelector(".lead-x").onclick = close;
      dlg.querySelector(".lead-close").onclick = close;
      dlg.addEventListener("click", function (e) { if (e.target === dlg) close(); });
      form.addEventListener("input", function () { err.textContent = ""; });
      form.addEventListener("submit", function (e) {
        e.preventDefault();
        var nume = form.nume.value.trim(), tel = form.telefon.value.replace(/\s/g, ""), gdpr = form.querySelector(".consent input").checked;
        if (!nume) { err.textContent = "Completează numele și prenumele."; form.nume.focus(); return; }
        if (!/^(\+4)?07\d{8}$/.test(tel)) { err.textContent = "Introdu un număr de telefon valid, de forma 07xx xxx xxx."; form.telefon.focus(); return; }
        if (!gdpr) { err.textContent = "Bifează acordul ca să te putem suna."; return; }
        // Trimite cererea către server, unde se salvează pentru panoul de administrare
        var btn = form.querySelector("button[type=submit]");
        btn.disabled = true;
        fetch("api/lead.php", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ nume: nume, telefon: tel, suma: +range.value, consimtamant: gdpr, website: form.website ? form.website.value : "", sursa: location.pathname + location.search })
        })
          .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
          .then(function (res) {
            if (!res.ok) { err.textContent = res.error || "Nu am putut trimite cererea. Încearcă din nou sau sună-ne."; return; }
            body.hidden = true; ok.hidden = false; form.reset(); range.oninput();
          })
          .catch(function () { err.textContent = "Nu am putut trimite cererea. Verifică internetul și încearcă din nou."; })
          .then(function () { btn.disabled = false; });
      });
    }

    // Video explicativ: pornește la apăsarea butonului de play
    var vid = document.getElementById("vid");
    if (vid) {
      var media = vid.parentElement;
      media.querySelector(".vid-play").onclick = function () {
        media.classList.add("playing"); vid.controls = true; vid.play();
      };
    }

    // Meniu mobil
    var mb = document.querySelector(".menu-btn");
    var nav = document.querySelector(".nav");
    if (mb && nav) mb.onclick = function () { nav.classList.toggle("open"); };

    // Calculator: indicator semicerc + grafic cu bare pe perioade
    var sum = document.getElementById("suma");
    if (sum) {
      var luni = document.getElementById("luni");
      var fmt = new Intl.NumberFormat("ro-RO", { maximumFractionDigits: 0 });
      var STANDARD = 0.12; // dobândă anuală de exemplu, de confirmat cu CAR
      var REDUCERE = 0.01;
      var PERIOADE = [12, 24, 36, 48, 60];
      var arc = document.getElementById("gauge-arc");
      var arcLen = arc.getTotalLength();
      arc.style.strokeDasharray = arcLen;
      var rata = function (P, an, n) { var r = an / 12; return P * r / (1 - Math.pow(1 + r, -n)); };
      var bars = document.getElementById("bars");
      bars.innerHTML = PERIOADE.map(function (n) { return '<div data-n="' + n + '"><i></i>' + n + ' luni</div>'; }).join("");

      var calc = function () {
        var P = +sum.value, n = +luni.value;
        var r1 = rata(P, STANDARD, n), r2 = rata(P, STANDARD - REDUCERE, n);
        document.getElementById("suma-out").textContent = fmt.format(P) + " lei";
        document.getElementById("luni-out").textContent = n + " luni";
        document.getElementById("rata-car").textContent = fmt.format(r2) + " lei";
        document.getElementById("rata-std").textContent = fmt.format(r1) + " lei";
        document.getElementById("economie").textContent = fmt.format((r1 - r2) * n) + " lei";
        var frac = (P - sum.min) / (sum.max - sum.min);
        var f = Math.max(.04, frac);
        arc.style.strokeDashoffset = arcLen * (1 - f);
        var pt = arc.getPointAtLength(arcLen * f);
        var knob = document.getElementById("gauge-knob");
        knob.setAttribute("cx", pt.x); knob.setAttribute("cy", pt.y);

        var max = rata(P, STANDARD - REDUCERE, PERIOADE[0]);
        bars.querySelectorAll("div").forEach(function (d) {
          var m = +d.dataset.n, v = rata(P, STANDARD - REDUCERE, m);
          var i = d.querySelector("i");
          i.style.height = Math.round(18 + 72 * v / max) + "%";
          i.setAttribute("data-v", fmt.format(v) + " lei");
          d.className = m === n ? "on" : "";
        });
      };
      sum.oninput = luni.oninput = calc;
      bars.onclick = function (e) {
        var d = e.target.closest("[data-n]");
        if (d) { luni.value = d.dataset.n; calc(); }
      };
      calc();
    }

    // Formular contact (demo, nu trimite nimic)
    var f = document.getElementById("contact-form");
    if (f) f.onsubmit = function (e) {
      e.preventDefault();
      if (!f.reportValidity()) return;
      document.querySelector(".form-ok").style.display = "block";
      f.reset();
    };
  });
})();
