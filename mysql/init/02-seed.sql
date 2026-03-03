INSERT INTO `owasp_categories` (`code`, `name`, `description`) VALUES
('A01', 'Broken Access Control', 'Restrictions on what authenticated users are allowed to do are often not properly enforced.'),
('A02', 'Security Misconfiguration', 'Missing appropriate security hardening across any part of the application stack, or improperly configured permissions.'),
('A03', 'Software Supply Chain Failures', 'Vulnerabilities introduced through third-party components, libraries, dependencies, or compromised build pipelines.'),
('A04', 'Cryptographic Failures', 'Failures related to cryptography which often lead to sensitive data exposure or system compromise.'),
('A05', 'Injection', 'Hostile data is sent to an interpreter as part of a command or query (SQL, XSS, OS command, etc.), leading to unintended commands or data access.'),
('A06', 'Insecure Design', 'Missing or ineffective security controls and architectural flaws that cannot be fixed by a perfect implementation.'),
('A07', 'Authentication Failures', 'Weaknesses in authentication and session management that allow attackers to compromise passwords, keys, or session tokens.'),
('A08', 'Software and Data Integrity Failures', 'Code and infrastructure that does not protect against integrity violations, including insecure CI/CD pipelines and auto-updates.'),
('A09', 'Logging and Alerting Failures', 'Insufficient logging, detection, monitoring, and active alerting that delays or prevents incident response.'),
('A10', 'Mishandling of Exceptional Conditions', 'Improper handling of errors, exceptions, and edge cases that can lead to crashes, information leaks, or security bypasses.');
