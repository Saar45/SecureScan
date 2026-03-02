INSERT INTO `owasp_categories` (`code`, `name`, `description`) VALUES
('A01', 'Broken Access Control', 'Restrictions on what authenticated users are allowed to do are often not properly enforced.'),
('A02', 'Cryptographic Failures', 'Failures related to cryptography which often lead to sensitive data exposure.'),
('A03', 'Injection', 'Hostile data is sent to an interpreter as part of a command or query, leading to unintended commands or data access.'),
('A04', 'Insecure Design', 'Missing or ineffective security controls and architectural flaws.'),
('A05', 'Security Misconfiguration', 'Missing appropriate security hardening across any part of the application stack.'),
('A06', 'Vulnerable and Outdated Components', 'Using components with known vulnerabilities or outdated versions.'),
('A07', 'Identification and Authentication Failures', 'Weaknesses in authentication and session management.'),
('A08', 'Software and Data Integrity Failures', 'Code and infrastructure that does not protect against integrity violations.'),
('A09', 'Security Logging and Monitoring Failures', 'Insufficient logging, detection, monitoring, and active response.'),
('A10', 'Server-Side Request Forgery', 'SSRF flaws occur when a web application fetches a remote resource without validating the user-supplied URL.');
