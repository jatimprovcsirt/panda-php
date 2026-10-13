# Security Policy

## Reporting a Vulnerability

PANDA is a cryptographic SDK handling personal data under Indonesia's UU PDP (Law No. 27/2022). Security vulnerabilities are treated with the highest priority.

### How to report

1. **DO NOT** open a public GitHub issue for security vulnerabilities.
2. Email: **csirt@jatimprov.go.id** (or use GitHub's private vulnerability reporting if enabled on this repository).
3. Include:
   - Description of the vulnerability
   - Steps to reproduce
   - Affected versions / language packages
   - Potential impact assessment

### Response timeline

| Step | Target |
|---|---|
| Acknowledgment of report | Within 48 hours |
| Initial assessment | Within 5 business days |
| Patch development | Coordinated across all three language packages simultaneously |
| Public disclosure | After patch is released, with CVE requested where applicable |

### Scope

Security issues in the following areas are in scope:

- **Core crypto engine** — nonce reuse, authentication bypass, key leakage, plaintext exposure
- **Envelope format** — parsing vulnerabilities, format confusion attacks
- **Key management** — key exposure via logs, error messages, telemetry
- **Blind indexing** — information leakage beyond documented equality patterns
- **Migration tool** — data corruption, checkpoint manipulation
- **Telemetry client** — unintended data exfiltration

### Out of scope

- Vulnerabilities in dependencies not maintained by PANDA (report upstream)
- Issues requiring physical access to the host machine
- Social engineering attacks

## Supported Versions

During the `0.y.z` pre-GA phase, only the latest minor release of each language package receives security patches. Post-GA (`1.0.0+`), the support policy will be formalized.
