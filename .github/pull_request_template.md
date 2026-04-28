<!--
Thanks for the contribution! A few quick checks before this PR is ready for review.
-->

## Summary

<!-- One or two sentences. What does this change and why? -->

## Type of change

- [ ] Bug fix (non-breaking)
- [ ] New feature (non-breaking)
- [ ] Breaking change (requires migration / readme.txt Upgrade Notice)
- [ ] Documentation only
- [ ] Internal refactor (no functional change)

## Related issue

<!-- Closes #123 / Refs #456 -->

## How was this tested?

- [ ] `composer check` passes locally (PHPCS + PHPStan + PHPUnit)
- [ ] `composer test:contract` passes (if you touched parent integration code)
- [ ] Manual QA on a wp-env install — describe what you walked through:

<!-- e.g. "Edited a campaign with monthly enabled, set $25/$50/$100 presets, viewed the preview pane, submitted a $50 monthly donation, verified the cart line item carries billing_period=month." -->

## Documentation

- [ ] `readme.txt` changelog entry added (if user-visible behavior changes)
- [ ] `docs/` updated (if a feature, hook, or admin UI changed)
- [ ] `.release-notes.md` will be updated at release time (no need to update on the PR)

## Security

- [ ] No new direct SQL outside `$wpdb->prepare`
- [ ] All new admin handlers verify nonces + capabilities
- [ ] All new outputs are escaped at echo time
- [ ] No new remote runtime fetches
- [ ] If the change touches donor-form submit: `Frontend\Submit_Guard` still rejects out-of-range / unauthorized cadence values

## Backward compatibility

- [ ] Existing v0.6.x and v1.0.x campaigns continue to render unchanged after this change

<!-- If this is a breaking change, describe the migration path here. -->
