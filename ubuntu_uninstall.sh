# Remove multiplexer
sudo systemctl stop sstp-multiplexer
sudo systemctl disable sstp-multiplexer
sudo rm /etc/systemd/system/sstp-multiplexer.service

sudo rm /usr/local/sstpmultipler/SSTPMultiplexer.php
sudo rmdir /usr/local/sstpmultipler

sudo rm /etc/sstpmultipler/config.ini
sudo rm /usr/local/sstpmultipler/misc/example_cert.pem
sudo rm /usr/local/sstpmultipler/misc/example_key.pem
sudo rmdir /usr/local/sstpmultipler/misc
sudo rmdir /etc/sstpmultipler

echo "-- Should not exist: ---"
ls -l /etc/sstpmultipler/
ls -l /usr/local/sstpmultipler/
ls -l /etc/systemd/system/sstp-multiplexer.service
