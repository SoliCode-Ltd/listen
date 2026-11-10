# R8 rules for the release build.
#
# Capacitor ships its own rules for plugin classes and their annotated methods
# (node_modules/@capacitor/android/capacitor/proguard-rules.pro); they cover
# ListenShellPlugin and SafePushNotificationsPlugin as well, because both extend Plugin.

# Plugins are looked up by class name from assets/capacitor.plugins.json.
-keepnames class com.capacitorjs.plugins.** { *; }

# Readable crash reports: keep file names and line numbers.
-keepattributes SourceFile,LineNumberTable
-renamesourcefileattribute SourceFile
