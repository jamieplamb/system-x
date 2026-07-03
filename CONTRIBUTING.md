# Contributing

Thanks for the interest. A few things worth knowing before you open anything.

## How this repo works

Day-to-day development happens in a private working repo. This public repo
receives a snapshot per release, which is why the history here is one commit per
version rather than a running log.

That changes how contributions land: anything accepted gets applied to the
working repo, credited to you, and ships here in the next release. It won't
appear as a merge commit on this repo directly.

## Issues

Issues are the best way in. Bug reports, feature requests, questions about
whether something is intended behaviour: all welcome. For bugs, include what you
did, what you expected, and what actually happened. A failing test is the gold
standard, but a clear reproduction is plenty.

## Pull requests

PRs are welcome, especially for small fixes. For anything bigger, open an issue
first so we can agree the approach before you sink time into it.

Before opening one:

- Run the tests. `composer test` covers the PHP and JS suites, and
  `php artisan dusk` runs the browser suite if your change touches the UI.
- Run Pint (`vendor/bin/pint`). CI checks style and will fail a PR that
  doesn't pass.
- Add tests for new behaviour. Every change in this project ships with tests,
  and yours should too.

## Security issues

Don't open a public issue for a vulnerability. See [SECURITY.md](SECURITY.md).
