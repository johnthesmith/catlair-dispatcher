for i in {1..1}; do
    curl "http://dispatcher:42002/demo/subproc_work?t=$i" &
done
wait
