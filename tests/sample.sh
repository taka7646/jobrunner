#!/bin/bash

echo "start $1"
for i in `seq 1 10`; do
	sleep 1
	echo $i
done
echo "end"
