/* global CRM, ts */
(function ($) {
  'use strict';

  var config = CRM.collationfixRunner;
  if (!config) {
    return;
  }

  function isQueueResponse(data) {
    return data && typeof data === 'object' && !Array.isArray(data) &&
      (data.is_error === 0 || data.is_error === 1 || typeof data.is_error === 'boolean') &&
      (data.is_continue === 0 || data.is_continue === 1 || typeof data.is_continue === 'boolean');
  }

  function showInterruption(xhr, status, started) {
    var data = xhr.responseJSON;
    if (!isQueueResponse(data)) {
      try {
        data = JSON.parse(xhr.responseText || '');
      }
      catch (e) {
        data = null;
      }
    }

    // A lost response does not mean the ALTER stopped. Core's Retry/Skip
    // endpoints steal the current task's lease, so don't offer them here.
    $('#crm-queue-runner-buttonset').hide();
    $('#crm-queue-runner-title').text(ts('Conversion response could not be confirmed'));
    var message = $('#crm-queue-runner-message').empty();
    var box = $('<div>').addClass('messages error no-popup').attr('role', 'alert').appendTo(message);
    $('<p>').text(ts('The queue has paused because the server did not return a usable response. The current database operation may still be running or may already have finished.')).appendTo(box);
    $('<p>').text(ts('Check the conversion log and database activity before starting another conversion. Once no conversion is running, return to the analysis page and review the remaining tables. Use the command-line API for long conversions.')).appendTo(box);
    $('<p>').text(ts('HTTP status: %1; request status: %2; elapsed: %3 seconds; queue: %4.', {
      1: xhr.status || 0,
      2: status,
      3: Math.round((Date.now() - started) / 1000),
      4: config.qrid
    })).appendTo(box);
    if (isQueueResponse(data) && typeof data.exception === 'string' && data.exception) {
      // Do not render server/proxy responses as HTML.
      $('<pre>').text(data.exception).appendTo(box);
    }
    var links = $('<p>').appendTo(box);
    $('<a>').attr('href', config.logUrl).text(ts('View conversion log')).appendTo(links);
    links.append(' | ');
    $('<a>').attr('href', config.analysisUrl).text(ts('Return to analysis')).appendTo(links);
  }

  // Register immediately, before the core runner starts its first request.
  // originalOptions.data is still the object passed by the core runner;
  // options.data may already have been URL-encoded by jQuery.
  $.ajaxPrefilter('json', function (options, originalOptions) {
    if (!originalOptions.data || originalOptions.data.qrid !== config.qrid ||
      config.urls.indexOf(originalOptions.url) === -1) {
      return;
    }
    var started = Date.now();
    var success = options.success;
    options.error = function (xhr, status) {
      showInterruption(xhr, status, started);
    };
    options.success = function (data, status, xhr) {
      // A 204 or JSON null/array can reach success without a queue result.
      if (!isQueueResponse(data)) {
        showInterruption(xhr, 'invalid-response', started);
        return;
      }
      success.apply(this, arguments);
    };
  });
})(CRM.$);
