# Launch readiness and adoption plan

## Pre-release checklist
- **Internationalization audit**: Run the WordPress i18n tools to ensure every UI string is wrapped with the `bokun-bookings-management` text domain. Verify `readme.txt` and the settings screen use consistent, localized copy.
- **Quality gate**: Execute `composer lint:phpcs` and `composer lint:phpstan` locally and through CI. Resolve any warnings prior to building the distributable ZIP.
- **Asset preparation**: Capture at least two 1200×900 PNG screenshots (settings screen and booking history table) and store them in `assets/wordpress-org/` using the WordPress.org naming convention (e.g., `screenshot-1.png`, `screenshot-2.png`).

## Packaging & release
1. Tag the repository with the semantic version (`git tag v1.0.0 && git push --tags`).
2. Allow the **Build release package** GitHub Action to produce the distributable ZIP. The workflow bundles production dependencies and publishes an artifact you can attach to GitHub releases or upload to WordPress.org.
3. Draft release notes summarizing new features, breaking changes, and required upgrade steps. Reference the changelog in `readme.txt`.

## Beta outreach
- Invite a small cohort of Bokun operators (tour companies, attractions) via industry Slack groups or mailing lists. Offer a private download link to the release artifact and request feedback within a two-week window.
- Collect structured feedback in a shared document or GitHub Discussions board, tagging items by category (UX, performance, compatibility).
- Schedule a follow-up release candidate once blockers are resolved, then promote the public launch through Bokun community forums and partner newsletters.

## Support expectations
- Publish a support guide outlining API credential troubleshooting, cron configuration, and CSV export tips.
- Monitor the WordPress.org support forum daily during the first month after release, aiming to respond within 24 hours.
- Automate changelog updates and security advisories through GitHub Releases to keep adopters informed of maintenance updates.
