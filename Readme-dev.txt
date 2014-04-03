
To create a new release :

1) create a new tag

Read the current version, with :

 # returns something like v0.1.1
 git describe --tags

Increment the version, like "v0.1.2"

 git tag v0.1.2

2) create release file

 # should create a file gedeon-sync-v0.1.2.zip in parent dir
 ./createArchive.sh



