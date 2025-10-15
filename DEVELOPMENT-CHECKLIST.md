# WordPress Plugin Development Checklist

## 🚨 CRITICAL: Always Follow This Checklist

### Before Writing Any Code
- [ ] Plan the plugin lifecycle (activation, deactivation, deletion)
- [ ] Identify all WordPress hooks you'll use
- [ ] Plan error handling strategy
- [ ] Plan security measures (nonces, capability checks, input validation)

### During Development
- [ ] Add deletion safety check: `if (defined('WP_UNINSTALL_PLUGIN')) return;`
- [ ] Check file existence before enqueueing assets
- [ ] Use REST API endpoints, never `template_redirect` for downloads
- [ ] Wrap risky operations in try-catch blocks
- [ ] Validate all user inputs
- [ ] Use proper WordPress functions (wp_remote_get, wp_insert_post, etc.)

### Before Testing
- [ ] Run `npm run lint-js --fix`
- [ ] Run `npm run build`
- [ ] Run `php -l` on all PHP files
- [ ] Check for any console.log statements (remove them)

### Testing Phase (MANDATORY)
- [ ] Install plugin on fresh WordPress installation
- [ ] Test all plugin functionality
- [ ] Check WordPress error logs for any errors
- [ ] Test plugin deactivation (should work cleanly)
- [ ] Test plugin deletion (should NOT cause critical errors)
- [ ] Test file downloads serve correct Content-Type
- [ ] Test on different WordPress versions
- [ ] Test with different themes
- [ ] Test with different plugins active

### Security Checklist
- [ ] All inputs are sanitized
- [ ] All outputs are escaped
- [ ] Nonces are verified for all forms
- [ ] Capability checks are in place
- [ ] File paths are validated (no directory traversal)
- [ ] No direct file access without WordPress functions

### Performance Checklist
- [ ] No memory leaks (check for unclosed resources)
- [ ] Reasonable time limits set for long operations
- [ ] Database queries are optimized
- [ ] Large files are handled efficiently
- [ ] Caching is implemented where appropriate

## 🚫 Common Mistakes to AVOID

### Plugin Lifecycle Mistakes
- ❌ Using `flush_rewrite_rules()` in deactivation hooks
- ❌ Not checking `WP_UNINSTALL_PLUGIN` constant
- ❌ Running cleanup code during deletion
- ❌ Not checking file existence before enqueueing

### File Handling Mistakes
- ❌ Using `template_redirect` for file downloads
- ❌ Using `exit()` or `die()` in WordPress contexts
- ❌ Not setting proper Content-Type headers
- ❌ Not validating file paths

### Security Mistakes
- ❌ Not sanitizing user inputs
- ❌ Not escaping outputs
- ❌ Not verifying nonces
- ❌ Not checking user capabilities
- ❌ Allowing directory traversal

### Testing Mistakes
- ❌ Skipping the deletion test
- ❌ Not checking error logs
- ❌ Only testing on one WordPress version
- ❌ Not testing with other plugins active

## 🛠️ Quick Commands

### Development Commands
```bash
# Run the automated test script
./test-plugin.sh

# Manual testing commands
npm run lint-js --fix
npm run build
php -l playground-bundler.php
```

### Emergency Debugging
```bash
# Check WordPress error logs
tail -f /path/to/wordpress/wp-content/debug.log

# Check PHP error logs
tail -f /var/log/php_errors.log
```

## 📚 Resources

- [WordPress Plugin API](https://developer.wordpress.org/plugins/)
- [WordPress Security Best Practices](https://developer.wordpress.org/plugins/security/)
- [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/)
- [WordPress REST API Handbook](https://developer.wordpress.org/rest-api/)

---

**Remember: It's better to spend extra time testing than to fix critical errors in production!**

