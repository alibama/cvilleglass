/* =====================================================================
   Charlottesville Glass — payment configuration
   ---------------------------------------------------------------------
   You only need to touch this file (and paste the same links into the
   buttons — see README "Turning on donations").

   workerUrl is OPTIONAL. Leave it "" and everything still works using
   plain Stripe Payment Links (the amount is chosen on Stripe's secure
   page). Set it to your deployed Cloudflare Worker URL to enable exact
   preset/custom amounts created server-side. See donate/README.md.
   ===================================================================== */
window.CVG = {
  // OPTIONAL Cloudflare Worker endpoint (creates Stripe Checkout Sessions).
  // e.g. "https://cvilleglass-donate.YOURNAME.workers.dev"
  workerUrl: ""
};
