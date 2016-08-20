#!/bin/bash
COUNT=10
if [ "$1" != "" ] ; then
  COUNT=$1
fi
echo "start $COUNT"
for i in `seq 1 $COUNT`; do
	sleep 1
	echo $i
done
echo "end"
