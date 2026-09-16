# CSPC organizational master-data update

## Scope

Replace the flat, stale organizational-unit seed with the approved CSPC
classification → optional branch → selectable leaf hierarchy.  The existing
`organizational_units` table remains the only operational master-data source.
Request-version affiliation snapshots remain immutable compatibility data.

## Steps

1. Add the final hierarchy through a reusable seeder/migration path that
   retains established unit codes and IDs where they are unambiguous, and
   deactivates only named legacy rows.
2. Make `OrganizationalUnit` resolve a classification through its ancestors
   and distinguish active selectable leaves from classifications and branches.
3. Replace the runtime hard-coded office catalogue with queries against the
   organizational-unit hierarchy, retaining request-version snapshots only as
   historical report-filter compatibility values.
4. Update ICTU administration and Account Settings terminology/display while
   keeping official assignment changes ICTU-only and request IDs authoritative.
5. Add focused coverage for hierarchy resolution, selection, assignment,
   request validation, profile display, reports/analytics options, and
   historical references.  With Docker unavailable, perform syntax/static
   verification and provide the exact Docker test commands rather than claim
   a green suite.
