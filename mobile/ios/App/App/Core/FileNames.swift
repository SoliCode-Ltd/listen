import Foundation

/// Makes a file name coming from a page safe to store on the phone, and makes sure its
/// extension matches the kind of file. Arabic (and any other) letters are kept.
///
/// Foundation only, on purpose: the unit tests run it without a phone. It says the same
/// as the Android app's `FileNames.java`; keep the two in step.
enum FileNames {
    static let fallbackBase = "listen-audio"
    private static let maxBaseLength = 80

    /// For each type: the extensions that are acceptable, the first one being the default.
    private static let extensions: [String: [String]] = [
        "audio/mpeg": ["mp3"],
        "audio/mp3": ["mp3"],
        "audio/wav": ["wav"],
        "audio/x-wav": ["wav"],
        "audio/wave": ["wav"],
        "audio/vnd.wave": ["wav"],
        "audio/ogg": ["ogg", "oga", "opus"],
        "audio/opus": ["opus"],
        "audio/mp4": ["m4a", "mp4"],
        "audio/x-m4a": ["m4a"],
        "audio/aac": ["aac", "m4a"],
        "audio/flac": ["flac"],
        "audio/x-flac": ["flac"],
        "audio/webm": ["webm", "weba"],
        "video/mp4": ["mp4", "m4v"],
        "video/webm": ["webm"],
        "video/quicktime": ["mov"]
    ]

    /// "audio/mpeg; codecs=..." becomes "audio/mpeg". Never nil.
    static func cleanMimeType(_ mimeType: String?) -> String {
        guard var value = mimeType else { return "" }
        if let semicolon = value.firstIndex(of: ";") {
            value = String(value[..<semicolon])
        }
        return value.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
    }

    /// Only sound and video files may be saved or shared through the bridge.
    static func isMediaType(_ mimeType: String?) -> Bool {
        cleanMimeType(mimeType).range(of: "^(audio|video)/[a-z0-9][a-z0-9.+-]*$", options: .regularExpression) != nil
    }

    /// The default extension for a type, or nil when the type is not one we know.
    static func extensionFor(_ mimeType: String?) -> String? {
        extensions[cleanMimeType(mimeType)]?.first
    }

    /// A safe file name with the right extension.
    /// Removes folders ("../x/y.mp3" becomes "y.mp3"), characters that file systems refuse,
    /// invisible direction and control characters, and leading dots; keeps letters of any language.
    static func sanitize(_ requestedName: String?, mimeType: String?) -> String {
        var name = requestedName ?? ""

        // Keep only the last path segment.
        if let slash = name.lastIndex(where: { $0 == "/" || $0 == "\\" }) {
            name = String(name[name.index(after: slash)...])
        }

        var cleaned = String.UnicodeScalarView()
        for scalar in name.unicodeScalars {
            if isSpace(scalar) {
                cleaned.append(" ") // tabs, line breaks and exotic spaces become one plain space
            } else if isForbidden(scalar) {
                continue
            } else if "<>:\"|?*".unicodeScalars.contains(scalar) {
                cleaned.append("_")
            } else {
                cleaned.append(scalar)
            }
        }
        name = String(cleaned)
        name = name.replacingOccurrences(of: " {2,}", with: " ", options: .regularExpression)
            .trimmingCharacters(in: .whitespaces)
        // Just an extension (".mp3") is not a name.
        if name.range(of: "^\\.[A-Za-z0-9]{1,5}$", options: .regularExpression) != nil { name = "" }
        // No hidden files, no names made only of dots.
        name = name.replacingOccurrences(of: "^[. ]+", with: "", options: .regularExpression)
            .replacingOccurrences(of: "[. ]+$", with: "", options: .regularExpression)

        var base = name
        var ext = ""
        if let dot = name.lastIndex(of: "."), dot != name.startIndex, name.index(after: dot) != name.endIndex {
            let candidate = name[name.index(after: dot)...].lowercased()
            if candidate.range(of: "^[a-z0-9]{1,5}$", options: .regularExpression) != nil {
                base = String(name[..<dot])
                ext = candidate
            }
        }
        base = base.replacingOccurrences(of: "[. ]+$", with: "", options: .regularExpression)
            .trimmingCharacters(in: .whitespaces)
        if base.isEmpty { base = fallbackBase }
        base = truncate(base, maxBaseLength)

        if let accepted = extensions[cleanMimeType(mimeType)] {
            if !accepted.contains(ext) {
                // "voice.txt" declared as audio/mpeg becomes "voice.txt.mp3": nothing is silently relabelled.
                if !ext.isEmpty { base = truncate(base + "." + ext, maxBaseLength) }
                ext = accepted[0]
            }
        } else if ext.isEmpty {
            ext = "bin"
        }
        return base + "." + ext
    }

    private static func isSpace(_ scalar: Unicode.Scalar) -> Bool {
        if scalar.properties.isWhitespace { return true }
        switch scalar.properties.generalCategory {
        case .spaceSeparator, .lineSeparator, .paragraphSeparator: return true
        default: return false
        }
    }

    private static func isForbidden(_ scalar: Unicode.Scalar) -> Bool {
        if scalar.value < 0x20 || scalar.value == 0x7f { return true } // control characters
        switch scalar.properties.generalCategory {
        // Invisible formatting characters (zero-width, bidirectional overrides), unassigned, private use.
        case .format, .control, .unassigned, .privateUse, .surrogate, .lineSeparator, .paragraphSeparator:
            return true
        default:
            return false
        }
    }

    /// Cuts by whole code points, as the Android app does (never through the middle of one).
    private static func truncate(_ value: String, _ maxScalars: Int) -> String {
        let scalars = value.unicodeScalars
        guard scalars.count > maxScalars else { return value }
        var cut = String.UnicodeScalarView()
        cut.append(contentsOf: scalars.prefix(maxScalars))
        return String(cut).trimmingCharacters(in: .whitespaces)
    }
}
