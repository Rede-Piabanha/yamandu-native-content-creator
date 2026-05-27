(function ($) {
  "use strict";

  var cfg = window.YamanduAdmin || null;

  function t(key, fallback) {
    if (cfg && typeof cfg[key] === "string" && cfg[key]) return cfg[key];
    return fallback || "";
  }

  function escText(v) {
    if (v === null || v === undefined) return "";
    return String(v).replace(/[\u0000-\u001F\u007F]/g, " ").replace(/\s+/g, " ").trim();
  }

  function supportedFields() {
    if (!cfg || !Array.isArray(cfg.supportedFields) || !cfg.supportedFields.length) {
      return ["title", "alt"];
    }
    return cfg.supportedFields.filter(function (field) {
      return field === "title" || field === "alt";
    });
  }

  function isSupportedField(field) {
    return supportedFields().indexOf(String(field || "")) !== -1;
  }

  function getConsentCheckbox() {
    return $("input[name$='[enable_third_party_requests]']").first();
  }

  function consentEnabled() {
    var $checkbox = getConsentCheckbox();
    if (!$checkbox.length) return true;
    return $checkbox.is(":checked");
  }

  function syncConsentDependentControls() {
    var $checkbox = getConsentCheckbox();
    if (!$checkbox.length) return;

    var enabled = $checkbox.is(":checked");
    var $validate = $("#yamandu-validate-key");

    if ($validate.length) {
      if (!enabled) {
        $validate.prop("disabled", true);
      } else if ($validate.attr("data-yamandu-consent-locked") !== "1") {
        $validate.prop("disabled", false);
      }
    }
  }

  $(document).on("change", "input[name$='[enable_third_party_requests]']", function () {
    syncConsentDependentControls();
  });

  $(function () {
    syncConsentDependentControls();
  });

  if (!cfg || !cfg.ajaxUrl) return;

  function setBtnState($btn, state, label) {
    if (!$btn || !$btn.length) return;
    var base = $btn.data("yamandu-label-base");
    if (!base) {
      base = $btn.text();
      $btn.data("yamandu-label-base", base);
    }
    if (state === "processing") {
      $btn
        .prop("disabled", true)
        .addClass("is-busy")
        .text(label || t("processingLabel", "Generating..."));
      return;
    }
    if (state === "done") {
      $btn
        .prop("disabled", false)
        .removeClass("is-busy")
        .text(label || t("doneLabel", "Generated"));
      window.setTimeout(function () {
        $btn.text(base);
      }, 1200);
      return;
    }
    if (state === "noop") {
      $btn
        .prop("disabled", false)
        .removeClass("is-busy")
        .text(label || t("noopLabel", "No changes"));
      window.setTimeout(function () {
        $btn.text(base);
      }, 1200);
      return;
    }
    if (state === "error") {
      $btn
        .prop("disabled", false)
        .removeClass("is-busy")
        .text(label || t("errorLabel", "Error"));
      window.setTimeout(function () {
        $btn.text(base);
      }, 1500);
      return;
    }
    $btn.prop("disabled", false).removeClass("is-busy").text(base);
  }

  function getAttachmentIdFromContext($el) {
    var id = parseInt($el.data("attachment-id"), 10);
    if (id) return id;

    var $row = $el.closest("tr[id^='post-']");
    if ($row.length) {
      var rid = parseInt(String($row.attr("id") || "").replace("post-", ""), 10);
      if (rid) return rid;
    }

    var $att = $el.closest(".attachment[data-id]");
    if ($att.length) {
      var aid = parseInt($att.attr("data-id"), 10);
      if (aid) return aid;
    }

    var $details = $(".media-modal .attachment-details[data-id]").first();
    if ($details.length) {
      var did = parseInt($details.attr("data-id"), 10);
      if (did) return did;
    }

    var $pid = $("#post_ID");
    if ($pid.length) {
      var pid = parseInt($pid.val(), 10);
      if (pid) return pid;
    }

    return 0;
  }

  function updateEditAttachmentFields(fields) {
    if (!fields) return;

    if (typeof fields.title === "string") {
      var $t = $("#title").first();
      if ($t.length) $t.val(fields.title).trigger("change");
    }

    if (typeof fields.alt === "string") {
      var $a = $("#attachment_alt").first();
      if ($a.length) $a.val(fields.alt).trigger("change");
    }
  }

  function updateMediaModalFields(fields) {
    if (!fields) return;

    var $details = $(".media-modal .attachment-details[data-id]").first();
    if (!$details.length) return;

    var $title = $details.find("input[data-setting='title'], input[name*='[title]']").first();
    var $alt = $details.find("input[data-setting='alt'], input[name*='[alt]']").first();

    if ($title.length && typeof fields.title === "string") $title.val(fields.title).trigger("change");
    if ($alt.length && typeof fields.alt === "string") $alt.val(fields.alt).trigger("change");
  }

  function updateFieldsFromResponse(resp) {
    if (!resp || !resp.fields) return;
    updateEditAttachmentFields(resp.fields);
    updateMediaModalFields(resp.fields);
  }

  function ajaxGenerate($btn, attachmentId, overwrite, fields) {
    var payload = {
      action: "yamandu_generate",
      nonce: cfg.nonce || "",
      attachment_id: attachmentId,
      overwrite: overwrite ? 1 : 0,
      fields: fields && fields.length ? fields : supportedFields()
    };

    setBtnState($btn, "processing");

    $.ajax({
      url: cfg.ajaxUrl,
      type: "POST",
      dataType: "json",
      data: payload
    })
      .done(function (res) {
        if (res && res.success) {
          var data = res.data || {};
          updateFieldsFromResponse(data);
          var updated = parseInt(data.updated, 10) || 0;
          if (updated > 0) {
            setBtnState($btn, "done");
          } else {
            setBtnState($btn, "noop");
          }
        } else {
          var msg = res && res.data && res.data.message ? res.data.message : t("errorLabel", "Error");
          setBtnState($btn, "error", escText(msg).slice(0, 36) || t("errorLabel", "Error"));
        }
      })
      .fail(function (xhr) {
        var msg = t("errorLabel", "Error");
        if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
          msg = xhr.responseJSON.data.message;
        }
        setBtnState($btn, "error", escText(msg).slice(0, 36) || t("errorLabel", "Error"));
      });
  }


  function imagePromptValue($btn) {
    var $wrap = $btn.closest(".yamandu-image-generator");
    var $prompt = $wrap.find(".yamandu-image-prompt").first();
    if (!$prompt.length) $prompt = $(".yamandu-image-prompt").first();
    return $prompt.length ? String($prompt.val() || "").trim() : "";
  }

  function setImageResult($btn, data) {
    var $wrap = $btn.closest(".yamandu-image-generator");
    var $result = $wrap.find(".yamandu-image-result").first();
    if (!$result.length) return;
    $result.empty();

    if (!data || !data.edit_url) return;

    $("<a/>", {
      href: data.edit_url,
      target: "_blank",
      rel: "noopener noreferrer",
      text: t("imageOpenLabel", "Open generated image")
    }).appendTo($result);

    if (data.url) {
      $("<span/>", { text: " " }).appendTo($result);
      $("<a/>", {
        href: data.url,
        target: "_blank",
        rel: "noopener noreferrer",
        text: t("imageGeneratedLabel", "Image generated")
      }).appendTo($result);
    }
  }

  function ajaxGenerateImage($btn, attachmentId, prompt) {
    setBtnState($btn, "processing");

    $.ajax({
      url: cfg.ajaxUrl,
      type: "POST",
      dataType: "json",
      data: {
        action: "yamandu_generate_image",
        nonce: cfg.nonce || "",
        attachment_id: attachmentId || 0,
        prompt: prompt
      }
    })
      .done(function (res) {
        if (res && res.success) {
          setImageResult($btn, res.data || {});
          setBtnState($btn, "done", t("imageGeneratedLabel", "Image generated"));
        } else {
          var msg = res && res.data && res.data.message ? res.data.message : t("errorLabel", "Error");
          setBtnState($btn, "error", escText(msg).slice(0, 36) || t("errorLabel", "Error"));
        }
      })
      .fail(function (xhr) {
        var msg = t("errorLabel", "Error");
        if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
          msg = xhr.responseJSON.data.message;
        }
        setBtnState($btn, "error", escText(msg).slice(0, 36) || t("errorLabel", "Error"));
      });
  }

  function setKeyStatus(message, stateOrOk) {
    var $status = $("#yamandu-key-status");
    var $badge = $(".yamandu-badge").first();

    var state = stateOrOk;

    if (state === null) state = "validating";
    else if (state === true || state === 1) state = "valid";
    else if (state === false || state === 0) state = "invalid";
    else if (typeof state !== "string") state = "invalid";

    if ($badge.length) {
      if (state === "validating") {
        $badge
          .removeClass("yamandu-badge-valid")
          .addClass("yamandu-badge-warn")
          .text(t("statusValidating", "Validating..."));
      } else if (state === "valid") {
        $badge
          .removeClass("yamandu-badge-warn")
          .addClass("yamandu-badge-valid")
          .text(t("badgeValidated", "Validated"));
      } else {
        $badge
          .removeClass("yamandu-badge-valid")
          .addClass("yamandu-badge-warn")
          .text(t("badgeNotValidated", "Not validated"));
      }
    }

    if (!$status.length) return;

    if (state === "invalid") {
      $status.text(escText(message || ""));
    } else {
      $status.text("");
    }

    var $hid = $("#yamandu-api-validated");
    if ($hid.length) {
      if (state === "valid") $hid.val("1");
      else if (state === "invalid") $hid.val("0");
    }
  }

  function updateModelSelect(models) {
    var $select = $("select[name$='[model]']").first();
    if (!$select.length) return;
    if (!Array.isArray(models) || !models.length) return;

    var current = $select.val();
    $select.empty();

    for (var i = 0; i < models.length; i++) {
      var m = escText(models[i]);
      if (!m) continue;
      $("<option/>", { value: m, text: m }).appendTo($select);
    }

    var still = false;
    $select.find("option").each(function () {
      if ($(this).val() === current) still = true;
    });

    if (still) $select.val(current);
    else $select.prop("selectedIndex", 0);
  }

  function ajaxValidateKey() {
    if (!consentEnabled()) {
      setKeyStatus(
        t(
          "consentRequired",
          "Enable third-party requests to validate the API key or run metadata generation."
        ),
        "invalid"
      );
      return;
    }

    var $input = $("input[name$='[api_key]']").first();
    var apiKey = $input.length ? String($input.val() || "").trim() : "";

    setKeyStatus("", "validating");

    $.ajax({
      url: cfg.ajaxUrl,
      type: "POST",
      dataType: "json",
      data: {
        action: "yamandu_validate_key",
        nonce: cfg.validateNonce || "",
        api_key: apiKey
      }
    })
      .done(function (res) {
        if (res && res.success) {
          var data = res.data || {};
          var ok = parseInt(data.valid, 10) === 1;

          if (ok) {
            if (Array.isArray(data.models) && data.models.length) updateModelSelect(data.models);
            setKeyStatus("", "valid");
          } else {
            var msg = data.message || t("statusFailed", "API key validation failed.");
            setKeyStatus(msg, "invalid");
          }
        } else {
          var msg2 =
            res && res.data && res.data.message ? res.data.message : t("statusFailed", "API key validation failed.");
          setKeyStatus(msg2, "invalid");
        }
      })
      .fail(function (xhr) {
        var msg3 = t("statusFailed", "API key validation failed.");
        if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
          msg3 = xhr.responseJSON.data.message;
        } else if (
          xhr &&
          typeof xhr.responseText === "string" &&
          xhr.responseText.trim() &&
          xhr.responseText.trim() !== "-1"
        ) {
          msg3 = xhr.responseText.trim();
        }
        setKeyStatus(msg3, "invalid");
      });
  }

  function ajaxRemoveKey() {
    var $btn = $("#yamandu-remove-key");
    if ($btn.length && $btn.data("yamandu-busy")) return;
    if ($btn.length) $btn.data("yamandu-busy", 1);

    var base = $btn.length ? $btn.text() : "";
    if ($btn.length) $btn.prop("disabled", true).text(t("statusRemoving", "Removing..."));

    setKeyStatus(t("statusRemoving", "Removing..."), null);

    $.ajax({
      url: cfg.ajaxUrl,
      type: "POST",
      dataType: "json",
      data: {
        action: "yamandu_remove_key",
        nonce: cfg.validateNonce || ""
      }
    })
      .done(function (res) {
        if (res && res.success) {
          var $input = $("input[name$='[api_key]']").first();
          if ($input.length) $input.val("");
          setKeyStatus(
            res.data && res.data.message ? res.data.message : t("statusRemoved", "API key removed."),
            false
          );
        } else {
          var msg =
            res && res.data && res.data.message ? res.data.message : t("statusFailed", "API key validation failed.");
          setKeyStatus(msg, false);
        }
      })
      .fail(function (xhr) {
        var msg = t("statusFailed", "API key validation failed.");
        if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
          msg = xhr.responseJSON.data.message;
        } else if (
          xhr &&
          typeof xhr.responseText === "string" &&
          xhr.responseText.trim() &&
          xhr.responseText.trim() !== "-1"
        ) {
          msg = xhr.responseText.trim();
        }
        setKeyStatus(msg, false);
      })
      .always(function () {
        if ($btn.length) {
          $btn
            .prop("disabled", false)
            .text(base || t("removeKeyLabel", "Remove key"))
            .data("yamandu-busy", 0);
        }
        syncConsentDependentControls();
      });
  }

  function isAttachmentEditScreen() {
    if ($('body.post-type-attachment').length) return true;

    var $postType = $('#post_type').first();
    if ($postType.length) {
      return String($postType.val() || '') === 'attachment';
    }

    var $postTypeInput = $("input[name='post_type']").first();
    if ($postTypeInput.length) {
      return String($postTypeInput.val() || '') === 'attachment';
    }

    return false;
  }

  function makeFieldActionBar(field, label) {
    if (!isSupportedField(field)) return null;

    var aiTip = t("fieldGenerateTip", "Generate with AI");
    var $bar = $("<div/>", { class: "yamandu-field-actions" }).attr("data-field", field);

    var $button = $("<button/>", {
      type: "button",
      class: "button button-small yamandu-generate",
      text: t("fieldPrefix", "AI"),
      title: aiTip + " " + label
    }).data("fields", [field]);

    $bar.append($button);
    return $bar;
  }

  function ensureEditAttachmentButtons() {
    if (!isAttachmentEditScreen()) return;

    var $pid = $("#post_ID");
    if (!$pid.length) return;

    var pid = parseInt($pid.val(), 10);
    if (!pid) return;

    if (isSupportedField("title")) {
      var $title = $("#title").first();
      if ($title.length) {
        var selT = ".yamandu-field-actions[data-field='title']";
        if (!$title.parent().find(selT).length) {
          var $titleBar = makeFieldActionBar("title", t("fieldTitleLabel", "Title"));
          if ($titleBar) {
            $titleBar.insertAfter($title);
          }
        }
      }
    }

    if (isSupportedField("alt")) {
      var $alt = $("#attachment_alt").first();
      if ($alt.length) {
        var selA = ".yamandu-field-actions[data-field='alt']";
        if (!$alt.parent().find(selA).length) {
          var $altBar = makeFieldActionBar("alt", t("fieldAltLabel", "Alt text"));
          if ($altBar) {
            $altBar.insertAfter($alt);
          }
        }
      }
    }
  }

  function ensureModalButtons() {
    var $details = $(".media-modal .attachment-details[data-id]").first();
    if (!$details.length) return;

    var id = parseInt($details.attr("data-id"), 10);
    if (!id) return;

    var $wrap = $details.find(".yamandu-actions").first();
    if (!$wrap.length) {
      $wrap = $("<div/>", { class: "yamandu-actions" });
      var $btn1 = $("<button/>", {
        type: "button",
        class: "button yamandu-generate",
        text: t("globalGenerateLabel", "Generate metadata with AI")
      }).attr("data-overwrite", "0");

      var $btn2 = $("<button/>", {
        type: "button",
        class: "button yamandu-generate",
        text: t("globalRegenerateLabel", "Regenerate metadata with AI")
      }).attr("data-overwrite", "1");

      $wrap.append($btn1).append(" ").append($btn2);

      var $imageBox = $("<div/>", { class: "yamandu-image-generator" });
      $("<label/>", { text: t("imagePromptLabel", "Image prompt") }).appendTo($imageBox);
      $("<textarea/>", {
        class: "widefat yamandu-image-prompt",
        rows: 3,
        placeholder: t("imagePromptPlaceholder", "Describe the image you want to create.")
      }).appendTo($imageBox);
      $("<button/>", {
        type: "button",
        class: "button button-primary yamandu-generate-image",
        text: t("imageGenerateLabel", "Generate image with AI")
      }).appendTo($imageBox);
      $("<div/>", { class: "yamandu-image-result", "aria-live": "polite" }).appendTo($imageBox);
      $wrap.append($imageBox);

      var $target = $details.find(".attachment-compat").first();
      if ($target.length) $target.prepend($wrap);
      else $details.append($wrap);
    }

    $wrap.find(".yamandu-generate, .yamandu-generate-image").each(function () {
      $(this).attr("data-attachment-id", String(id));
    });

    var mappings = [
      { field: "title", setting: "title", label: t("fieldTitleLabel", "Title") },
      { field: "alt", setting: "alt", label: t("fieldAltLabel", "Alt text") }
    ].filter(function (mapping) {
      return isSupportedField(mapping.field);
    });

    mappings.forEach(function (m) {
      var $set = $details.find(".setting[data-setting='" + m.setting + "']").first();
      if (!$set.length) return;
      var sel = ".yamandu-field-actions[data-field='" + m.field + "']";
      if ($set.find(sel).length) return;
      $set.append(makeFieldActionBar(m.field, m.label));
    });
  }

  function fieldCurrentValue(field) {
    if (field === "title") {
      var $title = $("#title").first();
      if ($title.length) return String($title.val() || "").trim();
      var $modalTitle = $(".media-modal .attachment-details[data-id]").find("input[data-setting='title'], input[name*='[title]']").first();
      if ($modalTitle.length) return String($modalTitle.val() || "").trim();
    }

    if (field === "alt") {
      var $alt = $("#attachment_alt").first();
      if ($alt.length) return String($alt.val() || "").trim();
      var $modalAlt = $(".media-modal .attachment-details[data-id]").find("input[data-setting='alt'], input[name*='[alt]']").first();
      if ($modalAlt.length) return String($modalAlt.val() || "").trim();
    }

    return "";
  }

  $(document).on("click", ".yamandu-generate", function (e) {
    e.preventDefault();
    var $btn = $(this);
    var attachmentId = getAttachmentIdFromContext($btn);
    if (!attachmentId) {
      setBtnState($btn, "error");
      return;
    }
    var fields = $btn.data("fields");
    if (!Array.isArray(fields) || !fields.length) fields = supportedFields();
    fields = fields.filter(function (field) {
      return isSupportedField(field);
    });
    if (!fields.length) {
      setBtnState($btn, "error");
      return;
    }

    var overwriteAttr = $btn.attr("data-overwrite");
    var overwrite;

    if (overwriteAttr === "0" || overwriteAttr === "1") {
      overwrite = overwriteAttr === "1";
    } else {
      overwrite = fields.some(function (field) {
        return fieldCurrentValue(field) !== "";
      });
    }

    ajaxGenerate($btn, attachmentId, overwrite, fields);
  });


  $(document).on("click", ".yamandu-generate-image", function (e) {
    e.preventDefault();

    var $btn = $(this);
    var attachmentId = getAttachmentIdFromContext($btn);
    var prompt = imagePromptValue($btn);

    if (!prompt) {
      setBtnState($btn, "error", t("imagePromptRequired", "Enter an image prompt first."));
      return;
    }

    ajaxGenerateImage($btn, attachmentId, prompt);
  });



  function normalizeGeneratedText(value) {
    if (value === null || value === undefined) return "";
    return String(value).replace(/\r\n/g, "\n").replace(/\r/g, "\n").replace(/\n{3,}/g, "\n\n").trim();
  }

  function htmlEscape(value) {
    return String(value || "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  function textToParagraphHtml(value) {
    var text = normalizeGeneratedText(value);
    if (!text) return "";
    return text.split(/\n{2,}/).map(function (paragraph) {
      return "<p>" + htmlEscape(paragraph).replace(/\n/g, "<br />") + "</p>";
    }).join("");
  }

  function plainTextFromHtml(value) {
    return $("<div/>").html(String(value || "")).text().replace(/\s+/g, " ").trim();
  }

  function getPostEditorId() {
    var $postId = $("#post_ID").first();
    if ($postId.length) {
      var id = parseInt($postId.val(), 10);
      if (id) return id;
    }
    if (window.wp && wp.data && wp.data.select) {
      try {
        var selectedId = wp.data.select("core/editor").getCurrentPostId();
        selectedId = parseInt(selectedId, 10);
        if (selectedId) return selectedId;
      } catch (err) {}
    }
    return 0;
  }

  function getClassicTextarea() {
    return $("textarea#content, textarea[name='content'], textarea[name='post_content']").first();
  }

  function getClassicEditorSelection() {
    var ed = window.tinymce && window.tinymce.get ? window.tinymce.get("content") : null;
    if (ed && !ed.isHidden()) {
      var selected = ed.selection ? ed.selection.getContent({ format: "text" }) : "";
      return String(selected || "").trim();
    }
    var $ta = getClassicTextarea();
    if ($ta.length) {
      var el = $ta.get(0);
      if (typeof el.selectionStart === "number" && typeof el.selectionEnd === "number" && el.selectionEnd > el.selectionStart) {
        return String($ta.val() || "").slice(el.selectionStart, el.selectionEnd).trim();
      }
    }
    return "";
  }

  function getBlockEditorSelection() {
    if (!window.wp || !wp.data || !wp.data.select) return "";
    try {
      var selectedBlock = wp.data.select("core/block-editor").getSelectedBlock();
      if (!selectedBlock || !selectedBlock.attributes) return "";
      var attrs = selectedBlock.attributes;
      var parts = [];
      ["content", "caption", "value", "text", "title"].forEach(function (key) {
        if (typeof attrs[key] === "string" && attrs[key].trim()) parts.push(plainTextFromHtml(attrs[key]));
      });
      return parts.join(" ").trim();
    } catch (err) {
      return "";
    }
  }

  function selectedEditorText() {
    return getBlockEditorSelection() || getClassicEditorSelection() || String(window.getSelection ? window.getSelection().toString() : "").trim();
  }

  function insertClassicText(text, replaceSelection) {
    var normalized = normalizeGeneratedText(text);
    if (!normalized) return false;

    var ed = window.tinymce && window.tinymce.get ? window.tinymce.get("content") : null;
    if (ed && !ed.isHidden()) {
      if (replaceSelection && ed.selection && String(ed.selection.getContent({ format: "text" }) || "").trim()) {
        ed.selection.setContent(textToParagraphHtml(normalized));
      } else {
        ed.execCommand("mceInsertContent", false, textToParagraphHtml(normalized));
      }
      if (typeof ed.save === "function") ed.save();
      if (typeof ed.fire === "function") ed.fire("change");
      return true;
    }

    var $ta = getClassicTextarea();
    if (!$ta.length) return false;
    var el = $ta.get(0);
    var current = String($ta.val() || "");
    var start = typeof el.selectionStart === "number" ? el.selectionStart : current.length;
    var end = typeof el.selectionEnd === "number" ? el.selectionEnd : current.length;
    var insert = normalized;

    if (!replaceSelection || end <= start) {
      insert = (start > 0 && current.charAt(start - 1) !== "\n" ? "\n\n" : "") + insert;
      if (start < current.length && current.charAt(start) !== "\n") insert += "\n\n";
      end = start;
    }

    $ta.val(current.slice(0, start) + insert + current.slice(end)).trigger("input").trigger("change");
    if (typeof el.focus === "function") el.focus();
    if (typeof el.setSelectionRange === "function") {
      var pos = start + insert.length;
      el.setSelectionRange(pos, pos);
    }
    return true;
  }

  function insertBlockEditorText(text, replaceSelection) {
    if (!window.wp || !wp.data || !wp.data.select || !wp.data.dispatch || !wp.blocks) return false;
    try {
      var blockEditor = wp.data.select("core/block-editor");
      var dispatch = wp.data.dispatch("core/block-editor");
      var selectedId = blockEditor.getSelectedBlockClientId();
      var blocks = normalizeGeneratedText(text).split(/\n{2,}/).filter(Boolean).map(function (paragraph) {
        return wp.blocks.createBlock("core/paragraph", { content: htmlEscape(paragraph).replace(/\n/g, "<br />") });
      });
      if (!blocks.length) return false;
      if (replaceSelection && selectedId) {
        dispatch.replaceBlock(selectedId, blocks);
      } else {
        dispatch.insertBlocks(blocks);
      }
      return true;
    } catch (err) {
      return false;
    }
  }

  function insertGeneratedText($wrap, replaceSelection) {
    var text = normalizeGeneratedText($wrap.find(".yamandu-text-result-text").first().val());
    if (!text) return false;
    var inserted = insertBlockEditorText(text, replaceSelection) || insertClassicText(text, replaceSelection);
    if (!inserted) {
      window.alert(t("textNoEditorLabel", "Could not find the post editor."));
    }
    return inserted;
  }

  function setTextResult($wrap, text) {
    var $result = $wrap.find(".yamandu-text-result").first();
    var $text = $wrap.find(".yamandu-text-result-text").first();
    $text.val(normalizeGeneratedText(text));
    $result.addClass("has-result").show();
  }

  function ajaxGenerateText($btn) {
    var $wrap = $btn.closest(".yamandu-text-generator");
    var prompt = String($wrap.find(".yamandu-text-prompt").first().val() || "").trim();
    if (!prompt) {
      setBtnState($btn, "error", t("textPromptRequired", "Enter a text prompt first."));
      return;
    }
    setBtnState($btn, "processing", t("textGenerateLabel", "Generate text with AI"));
    $.ajax({
      url: cfg.ajaxUrl,
      type: "POST",
      dataType: "json",
      data: {
        action: "yamandu_generate_text",
        nonce: cfg.nonce || "",
        post_id: getPostEditorId(),
        prompt: prompt,
        selection: selectedEditorText()
      }
    }).done(function (res) {
      if (res && res.success && res.data && typeof res.data.text === "string") {
        setTextResult($wrap, res.data.text);
        setBtnState($btn, "done", t("textGeneratedLabel", "Text generated"));
      } else {
        var msg = res && res.data && res.data.message ? res.data.message : t("errorLabel", "Error");
        setBtnState($btn, "error", escText(msg).slice(0, 36) || t("errorLabel", "Error"));
      }
    }).fail(function (xhr) {
      var msg = t("errorLabel", "Error");
      if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
        msg = xhr.responseJSON.data.message;
      }
      setBtnState($btn, "error", escText(msg).slice(0, 36) || t("errorLabel", "Error"));
    });
  }

  function buildTextGeneratorBox(extraClass) {
    var $wrap = $("<div/>", { class: "yamandu-text-generator " + (extraClass || "") }).attr("data-post-id", String(getPostEditorId()));
    $("<label/>", { text: t("textPromptLabel", "Text prompt") }).appendTo($wrap);
    $("<textarea/>", {
      class: "widefat yamandu-text-prompt",
      rows: 5,
      placeholder: t("textPromptPlaceholder", "Describe the text you want to create for this post.")
    }).appendTo($wrap);
    $("<p/>").append($("<button/>", {
      type: "button",
      class: "button button-primary yamandu-generate-text",
      text: t("textGenerateLabel", "Generate text with AI")
    })).appendTo($wrap);
    var $result = $("<div/>", { class: "yamandu-text-result", "aria-live": "polite" }).hide().appendTo($wrap);
    $("<textarea/>", { class: "widefat yamandu-text-result-text", rows: 8, readonly: "readonly" }).appendTo($result);
    $("<p/>", { class: "yamandu-text-result-actions" })
      .append($("<button/>", { type: "button", class: "button yamandu-insert-text", text: t("textInsertLabel", "Insert into editor") }))
      .append(" ")
      .append($("<button/>", { type: "button", class: "button yamandu-replace-text", text: t("textReplaceLabel", "Replace selection") }))
      .append(" ")
      .append($("<button/>", { type: "button", class: "button yamandu-copy-text", text: t("textCopyLabel", "Copy text") }))
      .appendTo($result);
    return $wrap;
  }

  function registerBlockEditorTextSidebar() {
    if (!cfg || parseInt(cfg.postTextContext, 10) !== 1) return;
    if (registerBlockEditorTextSidebar.done) return;
    if (!window.wp || !wp.plugins || !wp.editPost || !wp.element || !wp.components) return;
    var el = wp.element.createElement;
    var Fragment = wp.element.Fragment;
    var PluginSidebar = wp.editPost.PluginSidebar;
    var PluginSidebarMoreMenuItem = wp.editPost.PluginSidebarMoreMenuItem;
    var PanelBody = wp.components.PanelBody;
    if (!PluginSidebar || !PluginSidebarMoreMenuItem || !PanelBody) return;
    registerBlockEditorTextSidebar.done = true;
    wp.plugins.registerPlugin("yamandu-text-generator", {
      render: function () {
        return el(Fragment, {},
          el(PluginSidebarMoreMenuItem, { target: "yamandu-text-generator-sidebar" }, t("textGeneratorTitle", "Yamandu Text Generator")),
          el(PluginSidebar, { name: "yamandu-text-generator-sidebar", title: t("textGeneratorTitle", "Yamandu Text Generator") },
            el(PanelBody, { initialOpen: true },
              el("div", {
                className: "yamandu-text-generator-sidebar",
                ref: function (node) {
                  if (node && !node.getAttribute("data-yamandu-mounted")) {
                    node.setAttribute("data-yamandu-mounted", "1");
                    $(node).append(buildTextGeneratorBox("yamandu-text-generator-block"));
                  }
                }
              })
            )
          )
        );
      }
    });
  }

  $(document).on("click", ".yamandu-generate-text", function (e) {
    e.preventDefault();
    ajaxGenerateText($(this));
  });

  $(document).on("click", ".yamandu-insert-text", function (e) {
    e.preventDefault();
    insertGeneratedText($(this).closest(".yamandu-text-generator"), false);
  });

  $(document).on("click", ".yamandu-replace-text", function (e) {
    e.preventDefault();
    insertGeneratedText($(this).closest(".yamandu-text-generator"), true);
  });

  $(document).on("click", ".yamandu-copy-text", function (e) {
    e.preventDefault();
    var $btn = $(this);
    var text = normalizeGeneratedText($btn.closest(".yamandu-text-generator").find(".yamandu-text-result-text").first().val());
    if (!text) return;
    if (window.navigator && window.navigator.clipboard && window.navigator.clipboard.writeText) {
      window.navigator.clipboard.writeText(text).then(function () {
        setBtnState($btn, "done", t("textCopiedLabel", "Copied"));
      }).catch(function () {
        setBtnState($btn, "error", t("errorLabel", "Error"));
      });
    } else {
      var $tmp = $("<textarea/>").css({ position: "fixed", left: "-9999px", top: "0" }).val(text).appendTo(document.body);
      $tmp.get(0).select();
      try {
        document.execCommand("copy");
        setBtnState($btn, "done", t("textCopiedLabel", "Copied"));
      } catch (err) {
        setBtnState($btn, "error", t("errorLabel", "Error"));
      }
      $tmp.remove();
    }
  });

  $(document).on("click", "#yamandu-validate-key", function (e) {
    e.preventDefault();
    ajaxValidateKey();
  });

  $(document).on("click", "#yamandu-remove-key", function (e) {
    e.preventDefault();
    ajaxRemoveKey();
  });

  $(document).on(
    "click",
    ".media-modal .attachment, .media-modal .media-toolbar, .media-modal .media-sidebar",
    function () {
      window.setTimeout(ensureModalButtons, 50);
    }
  );

  if (window.MutationObserver) {
    var obs = new MutationObserver(function () {
      ensureModalButtons();
    });
    obs.observe(document.body, { childList: true, subtree: true });
  }

  $(function () {
    registerBlockEditorTextSidebar();
    if (window.wp && wp.domReady) wp.domReady(registerBlockEditorTextSidebar);
    ensureModalButtons();
    ensureEditAttachmentButtons();
    setTimeout(ensureEditAttachmentButtons, 600);
    setTimeout(ensureEditAttachmentButtons, 1600);
  });
})(jQuery);
