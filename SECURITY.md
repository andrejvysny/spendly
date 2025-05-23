# Security Policy

## Supported Versions

We actively maintain security updates for the following versions of Spendly:

No official release versions are available yet, but we are working on it.

| Version | Supported          |
| ------- | ------------------ |
| N/A     | :x:                |

## Reporting a Vulnerability

**Please do not report security vulnerabilities through public GitHub issues.**

Instead, please report them responsibly by emailing us at **vysnyandrej@gmail.com**.

### What to Include

When reporting a vulnerability, please include:

- A clear description of the vulnerability
- Steps to reproduce the issue
- Potential impact assessment
- Any suggested fixes (if available)
- Your contact information for follow-up

## Security Measures: WIP - Not all measures are implemented yet

### Financial Data Protection

As a personal finance application, Spendly implements multiple layers of security:

#### Data Encryption
- **In Transit**: All data transmission uses TLS 1.3
- **At Rest**: Database encryption for sensitive financial data
- **Application**: Encrypted storage of API keys and tokens

#### Authentication & Authorization
- **Multi-Factor Authentication**: Required for all user accounts
- **API Security**: OAuth 2.0 and rate limiting for API access
- **Session Management**: Secure session handling with automatic timeout
- **Role-Based Access**: Granular permissions for different user types

#### Banking Integration Security
- **GoCardless Integration**: Secure API handling with encrypted token storage
- **PCI DSS Compliance**: Following payment card industry standards
- **Bank-Level Security**: Implementing financial industry best practices

### Infrastructure Security

#### Application Security
- **Input Validation**: Comprehensive validation on all user inputs
- **SQL Injection Prevention**: Parameterized queries and ORM usage
- **XSS Protection**: Content Security Policy and output encoding
- **CSRF Protection**: Anti-CSRF tokens on all forms

#### Dependency Management
- **Automated Scanning**: Regular dependency vulnerability scans
- **Regular Updates**: Timely updates of security patches
- **License Compliance**: Monitoring for license violations

#### Deployment Security
- **Container Security**: Regular base image updates and scanning
- **Environment Isolation**: Proper separation of environments
- **Secrets Management**: Secure handling of environment variables
- **Access Controls**: Limited access to production systems

## Security Best Practices for Contributors

### Development
- **Secure Coding**: Follow OWASP Top 10 guidelines
- **Code Review**: All changes require security-focused review
- **Testing**: Include security test cases for new features
- **Documentation**: Document security implications of changes

### Handling Sensitive Data
- **Never commit secrets**: Use environment variables for sensitive data
- **Audit Logging**: Log security-relevant events without sensitive data
- **Data Retention**: Implement appropriate data retention policies

### Third-Party Integrations
- **API Security**: Validate all external API responses
- **Least Privilege**: Use minimal required permissions
- **Monitoring**: Monitor third-party service status and security
- **Fallback Plans**: Implement graceful degradation for service outages

### External Resources
- [OWASP Top 10](https://owasp.org/www-project-top-ten/)
- [CWE/SANS Top 25](https://cwe.mitre.org/top25/)
- [NIST Cybersecurity Framework](https://www.nist.gov/cyberframework)

## Disclaimer

The information provided in this security policy is for informational purposes only and does not constitute legal or professional advice. While we strive to maintain the highest security standards, we cannot guarantee the absolute security of our systems or data. Users are encouraged to take their own precautions and consult with security professionals as needed.

---

**Last Updated**: May 2025

**Note**: This document is a work in progress and will be updated as we implement more security measures and best practices.
