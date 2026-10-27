/* Charlottesville Glass — donation widget behaviour.
   Progressive enhancement only. With no JS (or no Worker configured) the
   "Donate" link and the $75 experience links still work as normal Stripe
   Payment Links; the amount is then chosen on Stripe's secure page.
   If window.CVG.workerUrl is set, a chosen/typed amount is turned into an
   exact Stripe Checkout Session by the Worker. */
(function () {
  "use strict";
  var C = (window.CVG || {});
  var form = document.getElementById("donate-form");
  if (!form) return;

  var amountInput = document.getElementById("donate-amount");
  var chips = Array.prototype.slice.call(form.querySelectorAll(".amount-chip"));
  var goLink = document.getElementById("donate-go");           // <a> fallback (Payment Link)
  var freqButtons = Array.prototype.slice.call(form.querySelectorAll(".give-freq button"));
  var frequency = "one_time";

  function setPressed(list, el) {
    list.forEach(function (b) { b.setAttribute("aria-pressed", b === el ? "true" : "false"); });
  }

  var defaultHref = goLink ? goLink.getAttribute("href") : null; // the "choose amount" Payment Link

  chips.forEach(function (chip) {
    chip.addEventListener("click", function () {
      setPressed(chips, chip);
      if (amountInput) amountInput.value = chip.getAttribute("data-amount");
      // If no Worker: use this chip's own fixed Payment Link when provided,
      // otherwise fall back to the "choose amount" link.
      if (!C.workerUrl && goLink) {
        goLink.setAttribute("href", chip.getAttribute("data-link") || defaultHref || "#");
      }
    });
  });

  if (amountInput) {
    amountInput.addEventListener("input", function () {
      setPressed(chips, null);
      if (!C.workerUrl && goLink && defaultHref) goLink.setAttribute("href", defaultHref);
    });
  }

  freqButtons.forEach(function (b) {
    b.addEventListener("click", function () {
      setPressed(freqButtons, b);
      frequency = b.getAttribute("data-freq") || "one_time";
    });
  });

  function currentAmountCents() {
    var v = parseFloat((amountInput && amountInput.value) || "0");
    if (!isFinite(v) || v <= 0) return 0;
    return Math.round(v * 100);
  }

  form.addEventListener("submit", function (e) {
    // No Worker configured → let the plain Payment Link (goLink href) handle it.
    if (!C.workerUrl) return; // native navigation via the <a> is used instead; see markup

    e.preventDefault();
    var cents = currentAmountCents();
    if (cents < 100) { if (amountInput) amountInput.focus(); return; }

    var btn = form.querySelector("[data-submit]");
    var label = btn ? btn.textContent : "";
    if (btn) { btn.setAttribute("disabled", "disabled"); btn.textContent = "Redirecting\u2026"; }

    fetch(C.workerUrl, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ kind: "donation", amount: cents, frequency: frequency })
    })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d && d.url) { window.location.href = d.url; return; }
        throw new Error((d && d.error) || "No checkout URL returned");
      })
      .catch(function () {
        if (btn) { btn.removeAttribute("disabled"); btn.textContent = label; }
        window.alert("Sorry — we couldn't start the donation. Please email hello@cvilleglass.org and we'll help.");
      });
  });

  // When a Worker IS configured, the submit button should behave like a button,
  // not follow the fallback link.
  if (C.workerUrl && goLink) {
    goLink.addEventListener("click", function (e) {
      e.preventDefault();
      if (form.requestSubmit) form.requestSubmit(); else form.dispatchEvent(new Event("submit", { cancelable: true }));
    });
  }
})();
