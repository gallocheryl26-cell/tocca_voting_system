<?php require_once '../tocca_admin/get_logo.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php $pageTitle = "Privacy Policy | TOCCA"; include __DIR__ . '/partials/voter_head.php'; ?>
</head>
<body class="voter-page voter-page--legal">
  <div class="voter-legal-doc">
    <a href="javascript:handleReturn()" class="voter-legal-back"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back</a>
    <div class="legal-card">
            <h1>Privacy Policy</h1>
            <p class="text-muted mb-4">Effective Date: October 20, 2026</p>

            <p class="mb-4">The Tatak Ormoc Consumers' Choice Awards ("TOCCA", "we", "our", or "us") respects your privacy and is
              committed to protecting the personal information that you share with us while participating in the
              2026 voting program. This Privacy Policy explains how we collect, use, disclose, and safeguard your
              information when you access our online voting platform or interact with us in relation to the awards.</p>

            <h2>1. Information We Collect</h2>
            <p>We collect only the information necessary to verify voter eligibility, secure the integrity of the
              voting process, and communicate important updates. This includes:</p>
            <ul>
              <li><strong>Personal Identification:</strong> Your name, mobile number, and unique voter identifier.</li>
              <li><strong>Verification Details:</strong> One-time passcodes (OTPs), draft codes, or other verification
                credentials you provide.</li>
              <li><strong>Voting Activity:</strong> Your selected categories, choices, and the date and time your vote was
                submitted.</li>
              <li><strong>Technical Information:</strong> Browser type, device information, and IP address captured to
                prevent fraudulent voting.</li>
            </ul>

            <h2>2. How We Use Your Information</h2>
            <p>Your data is used to:</p>
            <ul>
              <li>Authenticate voters and ensure compliance with the official voting guidelines.</li>
              <li>Process, record, and tally votes for the participating businesses.</li>
              <li>Provide status updates, confirmations, and important notifications about your participation.</li>
              <li>Maintain the security and reliability of the platform, including detecting and preventing fraudulent or
                prohibited activities.</li>
              <li>Generate aggregated, anonymized reports that help the City Government of Ormoc evaluate the awards
                program.</li>
            </ul>

            <h2>3. Data Sharing and Disclosure</h2>
            <p>We do not sell or lease your personal data. We may share information only with:</p>
            <ul>
              <li>Authorized TOCCA organizers and technical partners who require access to administer the event.</li>
              <li>Government entities or regulatory bodies when legally obligated to do so.</li>
              <li>Service providers (such as SMS gateways) engaged to deliver OTPs and other communications, bound by
                confidentiality agreements.</li>
            </ul>

            <h2>4. Data Retention</h2>
            <p>Personal information is retained only for as long as necessary to fulfill the purposes outlined in this
              policy. Voting records are archived in accordance with government retention requirements and securely
              deleted once they are no longer needed.</p>

            <h2>5. Data Security</h2>
            <p>We implement administrative, technical, and physical safeguards designed to protect your information from
              unauthorized access, disclosure, alteration, or destruction. While we strive to use commercially acceptable
              means to protect your personal information, no method of transmission over the internet is 100% secure, and
              we cannot guarantee absolute security.</p>

            <h2>6. Your Rights and Choices</h2>
            <p>You may request access to, correction of, or deletion of your personal data by contacting the TOCCA
              Organizing Committee. Requests will be evaluated in line with applicable laws and the legitimate
              requirements of the awards program.</p>

            <h2>7. Cookies and Tracking Technologies</h2>
            <p>Our platform uses limited cookies and similar technologies to support security, session management, and
              load balancing. These tools do not track your browsing behavior beyond the TOCCA platform.</p>

            <h2>8. Updates to This Policy</h2>
            <p>We may update this Privacy Policy from time to time to reflect changes in our practices or applicable
              regulations. Material updates will be posted on this page with a new effective date.</p>

            <h2>9. Contact Us</h2>
            <p>If you have questions or concerns about this Privacy Policy or our data practices, please contact the
              Tatak Ormoc Consumers' Choice Awards Organizing Committee through the City Government of Ormoc, Public
              Information Office.</p>

            <div class="mt-5">
              <button type="button" class="btn btn-voter-primary" style="max-width:280px;" onclick="handleReturn()">Return to previous page</button>
            </div>
    </div>
  </div>
  <script>
    function handleReturn() {
      if (document.referrer) {
        window.location.href = document.referrer;
        return;
      }

      if (window.history.length > 1) {
        window.history.back();
        return;
      }

      window.toccaVoterGo('index.php');
    }
  </script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>