# set -e
# set -x

args=("$@")
if [[ "$#" -eq "0" ]]; then
    echo "Need search string."
fi;
echo "Searching for $@ (skipping themes, css) ..."
echo

SEARCHFOR="$@"

function runsearch() {
    echo "# $1 ---------------"
    find $1 -name "*.*" -maxdepth $2 -print0 | xargs -0 grep -i "$SEARCHFOR" 2>/dev/null | grep -v .min.js | grep -v phpunit.result.cache | grep -v findstring.sh | grep -v composer.json | grep -v Binary | grep -v js/jquery | grep -v docs/archive
}

runsearch . 1
runsearch public 8
runsearch config 8
runsearch src 8
runsearch docs 8
runsearch db 8
runsearch templates 8
runsearch tests 8
runsearch utils 8
runsearch .github 8

