# Update to this version, keeping config files
echo "Use git fetch first :)"
DIR=`pwd`
sudo systemctl stop sstp-multiplexer
sudo chmod 0777 /usr/local/sstpmultipler/SSTPMultiplexer.php
PHP=$(which php)
echo "#!$PHP" | cat - $DIR/SSTPMultiplexer.php > /usr/local/sstpmultipler/SSTPMultiplexer.php
sudo chmod 0744 /usr/local/sstpmultipler/SSTPMultiplexer.php
sudo systemctl start sstp-multiplexer
