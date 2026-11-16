/* Charlottesville Glass — pay page totals and the admin cash-entry form. No dependencies. */
(function () {
  "use strict";

  function money(cents) {
    return "$" + (cents / 100).toLocaleString("en-US", { minimumFractionDigits: cents % 100 ? 2 : 0 });
  }

  // Pay buttons show the running total ("Pay $800") and wait for at least one month/week.
  document.querySelectorAll(".pay-form[data-price]").forEach(function (form) {
    var price = parseInt(form.getAttribute("data-price"), 10);
    var button = form.querySelector("[data-pay-button]");
    function update() {
      var count;
      var kind = form.getAttribute("data-kind");
      if (kind === "once" || kind === "hourly") {
        count = Math.max(1, parseInt(form.querySelector("[name=quantity]").value, 10) || 1);
      } else {
        count = form.querySelectorAll("[name='periods[]']:checked").length;
      }
      button.textContent = count ? "Pay " + money(price * count) : "Pick at least one";
      button.disabled = !count;
    }
    form.addEventListener("change", update);
    form.addEventListener("input", update);
    form.addEventListener("submit", function () {
      button.disabled = true;
      button.textContent = "Opening secure checkout…";
    });
    update();
  });

  // Donation box: show the amount on the button ("Donate $50").
  document.querySelectorAll(".pay-form[data-donate]").forEach(function (form) {
    var amount = form.querySelector("[name=amount]");
    var button = form.querySelector("[data-pay-button]");
    function update() {
      var cents = Math.round((parseFloat(amount.value) || 0) * 100);
      button.textContent = cents >= 100 ? "Donate " + money(cents) : "Donate";
    }
    amount.addEventListener("input", update);
    form.addEventListener("submit", function () {
      button.disabled = true;
      button.textContent = "Opening secure checkout…";
    });
    update();
  });

  // Admin "record a cash or check payment": only show the month/week/people box for the chosen class.
  var manual = document.querySelector("[data-manual-form]");
  if (manual) {
    var select = manual.querySelector("[name=product_id]");
    var showFor = function () {
      var kind = select.options[select.selectedIndex].getAttribute("data-kind");
      manual.querySelectorAll("[data-kind]").forEach(function (el) {
        if (el.tagName !== "OPTION") el.hidden = el.getAttribute("data-kind").split(" ").indexOf(kind) < 0;
      });
    };
    select.addEventListener("change", showFor);
    showFor();
  }
})();
