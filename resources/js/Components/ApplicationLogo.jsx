export default function ApplicationLogo(props) {
    const { className, ...rest } = props;
    return (
        <img
            {...rest}
            src="/laku-pos-logo.jpg"
            alt="LakuPOS"
            className={`object-contain ${className || ''}`}
        />
    );
}
