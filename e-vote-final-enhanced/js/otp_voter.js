/**
 * Shared voter OTP helpers (Firebase + server/Twilio fallback).
 */
(function (global) {
  const config = () => global.TOCCA_OTP_CONFIG || {};

  function getFirebaseErrorCode(error) {
    const code = String((error && (error.code || error.error?.code)) || "");
    const msg = String((error && error.message) || "");
    const blob = (code + " " + msg).toLowerCase();
    if (code && !code.includes("quota-exceeded-for-quota-metric")) return code;
    if (/BILLING_NOT_ENABLED/i.test(msg)) return "auth/billing-not-enabled";
    if (blob.includes("quota-exceeded") || blob.includes("send-verification-code-per-day")) {
      return "auth/quota-exceeded";
    }
    if (blob.includes("too-many-requests") || /TOO_MANY_ATTEMPTS_TRY_LATER/i.test(msg)) {
      return "auth/too-many-requests";
    }
    return code;
  }

  function isBillingError(error) {
    return getFirebaseErrorCode(error) === "auth/billing-not-enabled";
  }

  function isQuotaError(error) {
    const code = getFirebaseErrorCode(error);
    return code === "auth/quota-exceeded" || code === "auth/too-many-requests";
  }

  function isLocalhostHost() {
    return window.location.hostname === "localhost";
  }

  function isInvalidCredentialError(error) {
    const code = getFirebaseErrorCode(error);
    return (
      code === "auth/invalid-app-credential" ||
      code === "auth/missing-app-credential" ||
      /INVALID_APP_CREDENTIAL/i.test(String((error && error.message) || ""))
    );
  }

  function useServerMode() {
    const c = config();
    return c.provider === "server" || global.TOCCA_OTP_FORCE_SERVER === true;
  }

  function canFallbackFromFirebase(error) {
    if (config().can_fallback_server !== true) return false;
    return isBillingError(error) || isQuotaError(error);
  }

  function fallbackNotice(error) {
    if (isQuotaError(error)) {
      return "Using backup SMS because Firebase SMS is at capacity.";
    }
    return "Using backup SMS because Firebase could not send the code.";
  }

  function firebaseBillingMessage(error) {
    const c = config();
    if (c.firebase_sms_ready) {
      return (
        "Firebase SMS billing is active on the server, but your browser session may be outdated. " +
        "Press Ctrl+F5 to hard-refresh, complete reCAPTCHA again, then click Send OTP. " +
        "Also confirm Phone sign-in is enabled in Firebase Authentication."
      );
    }
    return (
      "Firebase SMS billing is not active yet for project tocca-voting-system. " +
      "Confirm Blaze is linked to this project (not a different Firebase project), " +
      "Phone sign-in is enabled under Authentication, and wait up to 24 hours after upgrading. " +
      (error && error.message ? ` (${error.message})` : "")
    );
  }

  function formatSendError(error) {
    const code = getFirebaseErrorCode(error);
    if (code === "auth/billing-not-enabled") {
      return firebaseBillingMessage(error);
    }
    if (isLocalhostHost() && (isInvalidCredentialError(error) || code === "auth/captcha-check-failed")) {
      return (
        "Firebase Phone Auth cannot run on http://localhost. This page should redirect to http://127.0.0.1 — " +
        "if it did not, open the same path using 127.0.0.1 and add 127.0.0.1 under Firebase Authentication → Authorized domains."
      );
    }
    if (code === "auth/captcha-check-failed" || isInvalidCredentialError(error)) {
      return (
        "reCAPTCHA verification failed. Hard-refresh (Ctrl+F5), complete the checkbox again, then click Send OTP. " +
        "Confirm your site domain is listed under Firebase Authentication → Settings → Authorized domains."
      );
    }
    if (code === "auth/operation-not-allowed") {
      return "Phone sign-in is disabled in Firebase. Enable it under Authentication → Sign-in method → Phone.";
    }
    if (code === "auth/quota-exceeded") {
      return "Failed to send OTP: SMS verification is temporarily at capacity. Please try again later today, or use Enter Access Code if you already registered.";
    }
    if (code === "auth/too-many-requests") {
      return "Too many OTP attempts. Wait a few minutes, then try again.";
    }
    if (error && error.message) {
      return "Failed to send OTP: " + error.message;
    }
    return "Failed to send OTP. Please try again.";
  }

  async function sendServerOtp(mobile, recaptchaToken, purpose) {
    const res = await fetch("send_otp.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "same-origin",
      body: JSON.stringify({
        mobile_number: mobile,
        recaptcha_response: recaptchaToken || "",
        purpose: purpose || "register",
      }),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || data.status === "error" || data.status === "blocked" || data.status === "closed") {
      const msg = data.message || "Unable to send OTP.";
      const err = new Error(msg);
      err.payload = data;
      throw err;
    }
    return data;
  }

  async function verifyServerOtp(mobile, code) {
    const res = await fetch("verify_otp.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "same-origin",
      body: JSON.stringify({ mobile_number: mobile, otp: code }),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || (data.status !== "verified" && data.status !== "success")) {
      const msg =
        data.message ||
        (data.status === "expired"
          ? "OTP expired. Please request a new code."
          : data.status === "invalid"
            ? "OTP did not match. Please try again."
            : "OTP verification failed.");
      const err = new Error(msg);
      err.payload = data;
      throw err;
    }
    return data;
  }

  const OTP_REPEAT_MS = 90000;

  function otpSendStorageKey(mobile) {
    const digits = String(mobile || "").replace(/\D/g, "");
    return "tocca_otp_sent:" + digits;
  }

  function recentOtpSendMs(mobile) {
    try {
      const at = parseInt(sessionStorage.getItem(otpSendStorageKey(mobile)) || "0", 10);
      if (!at) return 0;
      const left = OTP_REPEAT_MS - (Date.now() - at);
      return left > 0 ? left : 0;
    } catch (e) {
      return 0;
    }
  }

  function markOtpSent(mobile) {
    try {
      sessionStorage.setItem(otpSendStorageKey(mobile), String(Date.now()));
    } catch (e) {
      /* private mode can block storage; the in-flight lock still applies */
    }
  }

  function recentOtpSendMessage(mobile) {
    const secs = Math.ceil(recentOtpSendMs(mobile) / 1000);
    return "A code was just sent to this number. Enter that code. You can request another in " + secs + " seconds.";
  }

  async function loadConfig() {
    try {
      const res = await fetch("get_otp_config.php", { credentials: "same-origin" });
      if (res.ok) {
        global.TOCCA_OTP_CONFIG = await res.json();
      }
    } catch (e) {
      console.warn("Unable to load OTP config:", e);
    }
    return global.TOCCA_OTP_CONFIG || {};
  }

  global.ToccaOtp = {
    useServerMode,
    canFallbackFromFirebase,
    fallbackNotice,
    isBillingError,
    isQuotaError,
    isLocalhostHost,
    isInvalidCredentialError,
    getFirebaseErrorCode,
    firebaseBillingMessage,
    formatSendError,
    sendServerOtp,
    verifyServerOtp,
    loadConfig,
    recentOtpSendMs,
    markOtpSent,
    recentOtpSendMessage,
  };
})(window);
