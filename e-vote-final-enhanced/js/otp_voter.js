/**
 * Shared voter OTP helpers (Firebase + server/Twilio fallback).
 */
(function (global) {
  const config = () => global.TOCCA_OTP_CONFIG || {};

  function getFirebaseErrorCode(error) {
    const code = error && (error.code || error.error?.code);
    if (code) return code;
    const msg = String((error && error.message) || "");
    if (/BILLING_NOT_ENABLED/i.test(msg)) return "auth/billing-not-enabled";
    return "";
  }

  function isBillingError(error) {
    return getFirebaseErrorCode(error) === "auth/billing-not-enabled";
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
    return isBillingError(error) && config().can_fallback_server === true;
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
    isBillingError,
    isLocalhostHost,
    isInvalidCredentialError,
    getFirebaseErrorCode,
    firebaseBillingMessage,
    formatSendError,
    sendServerOtp,
    verifyServerOtp,
    loadConfig,
  };
})(window);
