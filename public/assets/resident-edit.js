/* Charlottesville Glass — the resident's page editor.
   Everything here is an extra: without JavaScript the plain textareas, file inputs and
   name box still work. */
(function () {
  "use strict";

  // ---- Rich text: Quill on each .rte box, copied into its textarea on save ----
  if (window.Quill) {
    document.querySelectorAll("[data-rte]").forEach(function (box) {
      var form = box.closest("form");
      var field = form.querySelector("[name='" + box.getAttribute("data-rte") + "']");
      var quill = new Quill(box, {
        theme: "snow",
        placeholder: "Start writing…",
        modules: {
          toolbar: [
            [{ header: 2 }, { header: 3 }],
            ["bold", "italic"],
            [{ list: "ordered" }, { list: "bullet" }],
            ["blockquote", "link"],
            ["clean"]
          ]
        }
      });
      field.hidden = true;
      box.closest("form").classList.add("has-rte");
      var dirty = false;
      quill.on("text-change", function () { dirty = true; });
      form.addEventListener("submit", function () {
        field.value = quill.getLength() > 1 ? quill.getSemanticHTML() : "";
        dirty = false;
      });
      window.addEventListener("beforeunload", function (e) {
        if (dirty) { e.preventDefault(); e.returnValue = ""; }
      });
    });
  }

  // ---- Photos: shrink big pictures before upload (also drops GPS/location data) ----
  var MAX_SIDE = 2400;
  function shrink(file) {
    if (!/^image\/(jpeg|png|webp)$/.test(file.type) || !window.createImageBitmap) return Promise.resolve(file);
    return createImageBitmap(file, { imageOrientation: "from-image" }).then(function (img) {
      var scale = Math.min(1, MAX_SIDE / Math.max(img.width, img.height));
      // Small PNGs (graphics, transparency) are left exactly as they are.
      if (file.type === "image/png" && scale === 1 && file.size < 4e6) return file;
      var canvas = document.createElement("canvas");
      canvas.width = Math.round(img.width * scale);
      canvas.height = Math.round(img.height * scale);
      canvas.getContext("2d").drawImage(img, 0, 0, canvas.width, canvas.height);
      return new Promise(function (resolve) {
        canvas.toBlob(function (blob) {
          // JPEGs are always re-encoded so location data is dropped; others only if it helps.
          if (!blob || (scale === 1 && file.type !== "image/jpeg" && blob.size >= file.size)) return resolve(file);
          resolve(new File([blob], file.name.replace(/\.\w+$/, "") + ".jpg", { type: "image/jpeg" }));
        }, "image/jpeg", 0.86);
      });
    }).catch(function () { return file; });
  }

  document.querySelectorAll("input[type=file][data-resize]").forEach(function (input) {
    var form = input.form;
    var ready = false;
    form.addEventListener("submit", function (e) {
      if (ready || !window.DataTransfer || !input.files.length) return;
      e.preventDefault();
      var button = form.querySelector("button[type=submit]");
      if (button) { button.disabled = true; button.textContent = "Preparing photos…"; }
      Promise.all(Array.prototype.map.call(input.files, shrink)).then(function (files) {
        var dt = new DataTransfer();
        files.forEach(function (f) { dt.items.add(f); });
        input.files = dt.files;
        if (button) button.textContent = "Uploading…";
        ready = true;
        form.submit();
      });
    });
  });

  // ---- glassdatabase.org lookup: type a name, pick a maker ----
  document.querySelectorAll("[data-gdb]").forEach(function (box) {
    var q = box.querySelector("[data-gdb-q]");
    var list = box.querySelector("[data-gdb-results]");
    var gid = box.querySelector("[data-gdb-gid]");
    var name = box.querySelector("[data-gdb-name]");
    var save = box.closest("form").querySelector("[data-gdb-save]");
    var optional = box.hasAttribute("data-gdb-optional");
    var timer, last = "";

    function pick(m, li) {
      gid.value = m.gid;
      if (name) name.value = m.name;
      if (optional) { q.value = m.name; last = m.name; }
      Array.prototype.forEach.call(list.children, function (c) { c.classList.toggle("is-picked", c === li); });
      if (save) save.disabled = false;
    }

    function render(makers) {
      list.innerHTML = "";
      if (!makers.length) {
        if (q.value.trim().length >= 2) {
          var none = document.createElement("li");
          none.className = "fine";
          none.textContent = optional ? "Not on glassdatabase.org yet — that’s fine, just add the name." : "No match on glassdatabase.org yet.";
          list.appendChild(none);
        }
        return;
      }
      makers.forEach(function (m) {
        var li = document.createElement("li");
        var b = document.createElement("button");
        b.type = "button";
        b.className = "gdb-hit";
        var strong = document.createElement("b");
        strong.textContent = m.name;
        var small = document.createElement("span");
        small.textContent = m.description || m.gid;
        b.appendChild(strong);
        b.appendChild(small);
        b.addEventListener("click", function () { pick(m, li); });
        li.appendChild(b);
        list.appendChild(li);
      });
      if (optional) {
        var hint = document.createElement("li");
        hint.className = "fine";
        hint.textContent = "Pick one to link their glassdatabase.org profile, or just keep typing.";
        list.appendChild(hint);
      }
    }

    function search() {
      var term = q.value.trim();
      if (term === last) return;
      last = term;
      gid.value = "";
      if (save) save.disabled = true;
      if (term.length < 2) { render([]); return; }
      fetch("/residency/gdb-search.php?q=" + encodeURIComponent(term), { credentials: "same-origin" })
        .then(function (r) { return r.ok ? r.json() : { makers: [] }; })
        .then(function (d) { if (q.value.trim() === term) render(d.makers || []); })
        .catch(function () { render([]); });
    }

    q.addEventListener("input", function () { clearTimeout(timer); timer = setTimeout(search, 300); });
    if (!optional && q.value.trim()) search();
  });
})();
