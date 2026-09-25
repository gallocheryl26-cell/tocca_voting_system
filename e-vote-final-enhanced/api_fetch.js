/**
 * Ensures session cookies are sent for voter API calls (same-origin credentials).
 */
(function () {
  const nativeFetch = window.fetch.bind(window);
  const SESSION_API = /(?:^|\/)(?:submit_vote|save_draft|load_all_drafts|get_finalized_answers|load_existing_votes|load_category_draft|verify_existing|register_new_voter|google_voter_login|link_google_voter|save_draft_code|verify_otp|send_otp|load_draft|check_voter_session|check_mobile_status|check_has_draft)\.php/i;

  window.fetch = function (input, init) {
    init = Object.assign({}, init);
    const url = typeof input === 'string' ? input : (input && input.url) || '';
    if (SESSION_API.test(url)) {
      init.credentials = init.credentials || 'same-origin';
    }
    return nativeFetch(input, init);
  };
})();
