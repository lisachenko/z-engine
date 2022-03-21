To regenerate the dockerfile, run `php Dockerfile.php > Dockerfile`
To regenerate headers, run: `docker buildx build . -o .`
To generate headers without checking (useful for getting a copy of the header file to identify any errors), comment out the last line of process.php

Symbols syntax:
- `x`: export symbol x
- `#x`: export definition x
- `//...`: Comment

Modifiers (after a symbol):
- `zts` / `nts`: Only import symbol on thread-safe/non-thread-safe
- `<x.y` / `>x.y` / `<=x.y` / `>=x.y` / `=x.y` / `!=x.y`: Only import symbol on a given PHP version

To add a new symbol:
- Check what PHP versions it's defined in and if it's in an `#ifdef` (specifically `ZTS`) and add modifiers as appropriate
- Add to bottom of symbols.txt *unless* required by another symbol (in which case list it above)
- Regenerate headers and ensure all builds pass
- Commit updated headers

To add a new PHP version:
- Add to Dockerfile.php
- Regenerate dockerfile
- Regenerate headers
- Add modifier for any removed symbols to exclude them
- Add modifier for any newly required symbols (ex. members of common classes)