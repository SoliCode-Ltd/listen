import Foundation

/// The numbers of the opening screen's moving logo: seven bars, the centre one the
/// tallest, with a swell that runs outwards from the centre. They are the Android app's
/// (`LaunchWaveView.java`), so both apps open the same way; the still logo of the launch
/// screen (Assets: LaunchLogo) is drawn from the same heights.
enum LaunchWave {
    /// Bar heights of the still logo, in points.
    static let heights: [Double] = [12.5, 55, 90, 125, 90, 55, 12.5]
    static let barWidth: Double = 12.5
    static let barStep: Double = 22.5
    /// One swell passes across the logo in this time.
    static let periodMs: Double = 1150
    /// How far a bar shrinks at the low point of the swell, as a share of its height.
    static let depth: Double = 0.46
    static let easeInMs: Double = 420

    /// The height of bar `index` at `elapsedMs`, as a share of its still height (0...1].
    /// It eases in from the still shape, so the first frame is the logo itself.
    static func scale(bar index: Int, elapsedMs: Double) -> Double {
        var ease = min(1, max(0, elapsedMs / easeInMs))
        ease = ease * ease * (3 - 2 * ease)
        // The swell runs outwards from the centre bar, the same on both sides.
        let lag = abs(Double(index) - Double(heights.count - 1) / 2) * 0.62
        let phase = 2 * Double.pi * (elapsedMs / periodMs) - lag
        let dip = 0.5 - 0.5 * cos(phase)
        return 1 - ease * depth * dip
    }
}
