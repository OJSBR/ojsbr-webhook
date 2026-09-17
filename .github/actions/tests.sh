#!/bin/bash

# The plugin's own tests inside PKP's continuous integration. Without this file
# the action runs no test at all and the job is green for nothing.
#
# A receiver of its own is started first, on the machine of the run, so that the
# delivery the plugin makes is really received and can be read back. The browser
# tests come next and the unit tests after them; neither may be skipped because
# the other failed, so the status of both is kept and the worst one is returned.

set +e
status=0

node plugins/generic/ojsbrWebhook/tests/receiver.js 3399 &
receiver=$!
sleep 1

npx cypress run  --headless --browser chrome  --config '{"specPattern":["plugins/generic/ojsbrWebhook/cypress/tests/functional/*.cy.js"]}'
cypress=$?
[ $cypress -ne 0 ] && status=$cypress

php lib/pkp/lib/vendor/bin/phpunit --configuration lib/pkp/tests/phpunit.xml --no-coverage plugins/generic/ojsbrWebhook/tests
phpunit=$?
[ $phpunit -ne 0 ] && status=$phpunit

kill $receiver 2>/dev/null

echo "tests.sh: cypress=$cypress phpunit=$phpunit"
return $status
